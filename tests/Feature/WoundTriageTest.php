<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\TriageEvent;
use App\Models\UploadToken;
use App\Models\User;
use App\Models\Visit;
use App\Services\AI\EscalationDetector;
use App\Services\AI\LiteLlmMultimodalClient;
use App\Services\AI\TriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Wiring tests for the #1701 wound-photo triage flow (D1/D2/D3/D5/D6).
 *
 * The LiteLLM multimodal client is faked (no gateway calls, no cost). These tests
 * prove the wiring: urgent renders the #1708 practice-number/000 response and logs
 * a triage event; both-fail returns the triage-unavailable urgent message; the
 * agreement path returns needs-review; the consent placeholder renders.
 */
class WoundTriageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $practitioner = Practitioner::factory()->create(['organization_id' => $org->id]);
        $patient = Patient::factory()->create();
        $this->user = User::factory()->patient($patient)->create();
        $this->visit = Visit::factory()->create([
            'patient_id' => $patient->id,
            'practitioner_id' => $practitioner->id,
            'organization_id' => $org->id,
        ]);

        config()->set('triage.enabled', true);
    }

    /**
     * Bind a fake LiteLLM client that returns scripted votes in call order.
     *
     * @param  array<int, array>  $votes
     */
    private function fakeClient(array $votes): void
    {
        $this->app->bind(LiteLlmMultimodalClient::class, function () use ($votes) {
            return new class($votes) extends LiteLlmMultimodalClient
            {
                private int $i = 0;

                public function __construct(private array $votes) {}

                public function classify(string $model, string $systemPrompt, string $userText, string $imageBase64, string $mimeType, array $options = []): array
                {
                    $vote = $this->votes[$this->i] ?? ['class' => null, 'confidence' => 0.0, 'ok' => false];
                    $this->i++;

                    return array_merge([
                        'class' => null,
                        'confidence' => 0.0,
                        'rationale' => null,
                        'features' => [],
                        'ok' => false,
                        'http' => 200,
                        'error' => null,
                    ], $vote);
                }
            };
        });
    }

    private function makeToken(string $token): UploadToken
    {
        return UploadToken::create([
            'token' => $token,
            'visit_id' => $this->visit->id,
            'created_by' => $this->user->id,
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function pngUpload(): UploadedFile
    {
        // PNG (not JPEG) so this test does not depend on GD JPEG support.
        return UploadedFile::fake()->image('wound.png', 800, 600);
    }

    public function test_single_voter_urgent_renders_practice_number_and_logs_event(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeClient([
            ['class' => 'urgent', 'confidence' => 0.9, 'ok' => true],
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
        ]);
        $this->makeToken('wt-urgent');

        $response = $this->postJson('/upload/wt-urgent', [
            'file' => $this->pngUpload(),
            'document_type' => 'wound_photo',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('triage.is_urgent', true)
            ->assertJsonPath('triage.class', 'urgent');

        // D5: reuses the exact #1708 critical response (practice number + 000).
        $message = $response->json('triage.message');
        $this->assertStringContainsString('9369 2800', $message);
        $this->assertStringContainsString('000', $message);
        $this->assertSame(EscalationDetector::CRITICAL_RECOMMENDED_ACTION, $message);

        // D5 audit: a triage event was logged.
        $this->assertDatabaseHas('triage_events', [
            'visit_id' => $this->visit->id,
            'verdict' => 'urgent',
            'reason' => 'or_gate_escalation',
        ]);
        $this->assertSame(1, TriageEvent::where('visit_id', $this->visit->id)->count());
    }

    public function test_low_confidence_needs_review_escalates_to_urgent(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeClient([
            ['class' => 'needs-review', 'confidence' => 0.5, 'ok' => true],
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
        ]);
        $this->makeToken('wt-lowconf');

        $response = $this->postJson('/upload/wt-lowconf', [
            'file' => $this->pngUpload(),
            'document_type' => 'wound_photo',
        ]);

        $response->assertStatus(201)->assertJsonPath('triage.is_urgent', true);
        $this->assertDatabaseHas('triage_events', ['visit_id' => $this->visit->id, 'verdict' => 'urgent']);
    }

    public function test_both_voters_fail_returns_triage_unavailable_urgent(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeClient([
            ['class' => null, 'confidence' => 0.0, 'ok' => false],
            ['class' => null, 'confidence' => 0.0, 'ok' => false],
        ]);
        $this->makeToken('wt-fail');

        $response = $this->postJson('/upload/wt-fail', [
            'file' => $this->pngUpload(),
            'document_type' => 'wound_photo',
        ]);

        $response->assertStatus(201)->assertJsonPath('triage.is_urgent', true);
        $message = $response->json('triage.message');
        $this->assertStringContainsString('could not automatically review', $message);
        $this->assertStringContainsString('9369 2800', $message);

        $this->assertDatabaseHas('triage_events', [
            'visit_id' => $this->visit->id,
            'verdict' => 'urgent',
            'reason' => 'both_calls_failed_unavailable',
            'unavailable' => true,
        ]);
    }

    public function test_both_agree_confident_returns_needs_review_and_logs_no_event(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeClient([
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
        ]);
        $this->makeToken('wt-review');

        $response = $this->postJson('/upload/wt-review', [
            'file' => $this->pngUpload(),
            'document_type' => 'wound_photo',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('triage.is_urgent', false)
            ->assertJsonPath('triage.class', 'needs-review');

        // needs-review is NOT a discharge - message says so.
        $this->assertStringContainsString('not a discharge', $response->json('triage.message'));

        // No urgent event logged on a needs-review verdict (D5 logs urgent only).
        $this->assertSame(0, TriageEvent::where('visit_id', $this->visit->id)->count());
    }

    public function test_non_wound_photo_does_not_trigger_triage(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->fakeClient([]);
        $this->makeToken('wt-plain');

        $response = $this->postJson('/upload/wt-plain', [
            'file' => $this->pngUpload(),
            'document_type' => 'photo',
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('triage'));
        $this->assertSame(0, TriageEvent::where('visit_id', $this->visit->id)->count());
    }

    public function test_consent_placeholder_renders_on_upload_screen(): void
    {
        $this->makeToken('wt-consent');

        $response = $this->get('/upload/wt-consent');

        $response->assertOk()
            ->assertSee('analysed by external AI providers')
            ->assertSee('Wound photo');
    }

    public function test_service_returns_two_valued_class_only(): void
    {
        $this->fakeClient([
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
        ]);

        $service = $this->app->make(TriageService::class);
        $verdict = $service->triageImage(base64_encode('x'), 'image/png', $this->visit);

        $this->assertContains($verdict['class'], ['urgent', 'needs-review']);
    }

    public function test_trace_fields_contain_no_phi_or_image(): void
    {
        $this->fakeClient([
            ['class' => 'urgent', 'confidence' => 0.9, 'ok' => true, 'rationale' => 'SECRET PHI RATIONALE'],
            ['class' => 'needs-review', 'confidence' => 0.9, 'ok' => true],
        ]);

        $service = $this->app->make(TriageService::class);
        $verdict = $service->triageImage(base64_encode('IMAGEBYTES'), 'image/png', $this->visit);
        $fields = $service->traceFields($verdict);

        $flat = json_encode($fields);
        $this->assertStringNotContainsString('IMAGEBYTES', $flat);
        $this->assertStringNotContainsString('SECRET PHI RATIONALE', $flat);
        $this->assertArrayNotHasKey('rationale', $fields);
        // Only the allowlisted keys are present.
        $allowed = [
            'trace_id', 'verdict', 'reason', 'escalated_by', 'unavailable', 'confidence_floor',
            'primary_model', 'primary_class', 'primary_confidence', 'primary_ok',
            'secondary_model', 'secondary_class', 'secondary_confidence', 'secondary_ok',
        ];
        $this->assertEqualsCanonicalizing($allowed, array_keys($fields));
    }
}
