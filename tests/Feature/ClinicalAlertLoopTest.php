<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\Practitioner;
use App\Models\User;
use App\Models\Visit;
use App\Services\ClinicalAlertNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mission #1718 B1: close the clinical loop.
 *
 * Proves that an urgent triage verdict (and a critical chat escalation) produce
 * a clinician-visible alert that is surfaced, pinned at the top, via the
 * DoctorController alerts payload.
 */
class ClinicalAlertLoopTest extends TestCase
{
    use RefreshDatabase;

    private User $doctorUser;

    private Practitioner $practitioner;

    private Organization $organization;

    private Patient $patient;

    private Visit $visit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->practitioner = Practitioner::factory()->create(['organization_id' => $this->organization->id]);
        $this->doctorUser = User::factory()->doctor($this->practitioner)->create();
        $this->patient = Patient::factory()->create();
        $this->visit = Visit::factory()->create([
            'patient_id' => $this->patient->id,
            'practitioner_id' => $this->practitioner->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_urgent_triage_creates_clinician_visible_alert_in_alerts_payload(): void
    {
        $document = Document::create([
            'fhir_document_reference_id' => 'DocumentReference/test',
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visit->id,
            'title' => 'wound.jpg',
            'document_type' => 'wound_photo',
            'content_type' => 'image',
            'file_path' => 'documents/test/wound.jpg',
            'file_size' => 1000,
            'file_hash' => str_repeat('a', 64),
            'status' => 'current',
            'document_date' => now()->toDateString(),
            'confidentiality_level' => 'M',
            'created_by' => $this->doctorUser->id,
        ]);

        // Simulate the triage caller creating the alert on an urgent verdict.
        $notifier = $this->app->make(ClinicalAlertNotifier::class);
        $notification = $notifier->urgentTriage($this->visit, $document->id, 'or_gate_escalation');

        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->doctorUser->id,
            'type' => ClinicalAlertNotifier::TYPE_URGENT_TRIAGE,
            'severity' => 'high',
            'visit_id' => $this->visit->id,
        ]);

        // Surfaced, pinned at the top, in the DoctorController alerts payload.
        $response = $this->actingAs($this->doctorUser)->getJson('/api/v1/doctor/alerts');

        $response->assertOk()
            ->assertJsonPath('data.0.type', ClinicalAlertNotifier::TYPE_URGENT_TRIAGE)
            ->assertJsonPath('data.0.severity', 'high')
            ->assertJsonPath('data.0.pinned', true)
            ->assertJsonPath('data.0.patient_id', $this->patient->id);
    }

    public function test_critical_chat_escalation_creates_clinician_visible_alert(): void
    {
        $notifier = $this->app->make(ClinicalAlertNotifier::class);
        $notification = $notifier->criticalChat($this->visit, "Message contains critical symptom: 'chest pain'");

        $this->assertNotNull($notification);

        $response = $this->actingAs($this->doctorUser)->getJson('/api/v1/doctor/alerts');

        $response->assertOk()
            ->assertJsonPath('data.0.type', ClinicalAlertNotifier::TYPE_CRITICAL_CHAT)
            ->assertJsonPath('data.0.pinned', true);
    }

    public function test_clinical_alerts_are_pinned_above_observation_alerts(): void
    {
        // An observation-derived weight-gain alert.
        \App\Models\Observation::factory()->create([
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visit->id,
            'code' => '29463-7',
            'value_quantity' => 80.0,
            'value_unit' => 'kg',
            'effective_date' => now()->subDays(2)->toDateString(),
        ]);
        \App\Models\Observation::factory()->create([
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visit->id,
            'code' => '29463-7',
            'value_quantity' => 83.0,
            'value_unit' => 'kg',
            'effective_date' => now()->toDateString(),
        ]);

        // A clinical alert (urgent triage) that must pin above it.
        $this->app->make(ClinicalAlertNotifier::class)->urgentTriage($this->visit, null, 'or_gate_escalation');

        $response = $this->actingAs($this->doctorUser)->getJson('/api/v1/doctor/alerts');

        $response->assertOk()
            ->assertJsonPath('data.0.type', ClinicalAlertNotifier::TYPE_URGENT_TRIAGE)
            ->assertJsonPath('data.1.type', 'weight_gain');
    }

    public function test_doctor_documents_endpoint_returns_triage_class_badge(): void
    {
        $document = Document::create([
            'fhir_document_reference_id' => 'DocumentReference/test2',
            'patient_id' => $this->patient->id,
            'visit_id' => $this->visit->id,
            'title' => 'wound2.jpg',
            'document_type' => 'wound_photo',
            'content_type' => 'image',
            'file_path' => 'documents/test/wound2.jpg',
            'file_size' => 1000,
            'file_hash' => str_repeat('b', 64),
            'status' => 'current',
            'document_date' => now()->toDateString(),
            'confidentiality_level' => 'M',
            'created_by' => $this->doctorUser->id,
        ]);

        \App\Models\TriageEvent::create([
            'visit_id' => $this->visit->id,
            'document_id' => $document->id,
            'verdict' => 'urgent',
            'reason' => 'or_gate_escalation',
            'escalated_by' => ['primary'],
            'unavailable' => false,
            'primary_model' => 'claude-opus-4-8',
            'primary_class' => 'urgent',
            'primary_confidence' => 0.9,
            'primary_ok' => true,
            'secondary_model' => 'gemini-3.5-flash',
            'secondary_class' => 'needs-review',
            'secondary_confidence' => 0.9,
            'secondary_ok' => true,
            'confidence_floor' => 0.7,
            'trace_id' => \Illuminate\Support\Str::uuid(),
        ]);

        $response = $this->actingAs($this->doctorUser)
            ->getJson("/api/v1/doctor/patients/{$this->patient->id}/documents");

        $response->assertOk()
            ->assertJsonPath('data.0.triage_class', 'urgent')
            ->assertJsonPath('data.0.id', $document->id);
    }

    public function test_patient_cannot_access_doctor_documents(): void
    {
        $patientUser = User::factory()->patient($this->patient)->create();

        $response = $this->actingAs($patientUser)
            ->getJson("/api/v1/doctor/patients/{$this->patient->id}/documents");

        $response->assertStatus(403);
    }
}
