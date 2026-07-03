<?php

namespace App\Services\AI;

use App\Models\Document;
use App\Models\TriageEvent;
use App\Models\Visit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Wound-photo triage orchestration (mission #1701, decisions D1-D6).
 *
 * Runs the two-voter ensemble (primary + secondary via the LiteLLM gateway),
 * applies the D1/D2 OR-gate (TriageConsensus, mirrored from eval/ensemble.py),
 * and returns a structured verdict: two-valued class, per-voter confidence and
 * rationale, and the patient-facing message. On an urgent verdict it reuses the
 * #1708 EscalationDetector critical response (D5) and logs a triage event.
 *
 * Everything that defines the operating point (voters, floor, thresholds,
 * prompts, gateway, Langfuse) comes from config('triage.*') so a v4 point is a
 * config change plus a ~$5 eval re-run - no code rework.
 *
 * Privacy: no image bytes and no rationale free-text leave this class except in
 * the structured verdict returned to the caller. Logs, the triage_events row,
 * and Langfuse traces carry only the non-PHI fields listed in traceFields().
 */
class TriageService
{
    public function __construct(
        private LiteLlmMultimodalClient $client,
        private PromptLoader $promptLoader,
        private TriageConsensus $consensus,
        private EscalationDetector $escalationDetector,
    ) {}

    /**
     * Triage a patient-submitted wound photo document.
     *
     * @return array{
     *   class: string,
     *   is_urgent: bool,
     *   reason: string,
     *   escalated_by: array<int,string>,
     *   unavailable: bool,
     *   message: string,
     *   voters: array<string, array{model: string, class: ?string, confidence: float, rationale: ?string, ok: bool}>,
     *   confidence_floor: float,
     *   trace_id: string,
     *   event_id: ?string
     * }
     */
    public function triageDocument(Document $document): array
    {
        [$imageBase64, $mimeType] = $this->loadImage($document);

        return $this->triageImage($imageBase64, $mimeType, $document->visit, $document);
    }

    /**
     * Triage raw image bytes. Kept separate so it is unit-testable without a
     * stored Document.
     */
    public function triageImage(string $imageBase64, string $mimeType, ?Visit $visit = null, ?Document $document = null): array
    {
        $floor = (float) config('triage.confidence_floor', 0.7);
        $traceId = (string) Str::uuid();

        $primaryModel = (string) config('triage.voters.primary');
        $secondaryModel = (string) config('triage.voters.secondary');

        $userText = 'Triage this single post-surgical wound photograph and return the JSON object described in your instructions.';

        // D1: both voters via the LiteLLM gateway. The single-source-of-truth
        // prompts are loaded from prompts/*.md by the app PromptLoader.
        $primarySystem = $this->promptLoader->load((string) config('triage.prompts.primary'));
        $secondarySystem = $this->promptLoader->load((string) config('triage.prompts.secondary'));

        $primary = $this->client->classify(
            $primaryModel,
            $primarySystem,
            $userText,
            $imageBase64,
            $mimeType,
            $this->voterOptions('primary'),
        );

        $secondary = $this->client->classify(
            $secondaryModel,
            $secondarySystem,
            $userText,
            $imageBase64,
            $mimeType,
            $this->voterOptions('secondary'),
        );

        // D2 OR-gate (mirrored from eval/ensemble.py).
        $decision = $this->consensus->decide($primary, $secondary, $floor);

        $isUrgent = $decision['class'] === TriageVerdict::Urgent->value;

        // D5: urgent reuses the #1708 EscalationDetector critical response. The
        // triage-unavailable path (both voters failed) gets the required
        // "triage unavailable, call the practice" wording, still escalating.
        if ($decision['unavailable']) {
            $message = 'We could not automatically review your photo right now. To be safe, please call the practice on (02) 9369 2800; in an emergency call 000. Do not wait.';
        } elseif ($isUrgent) {
            $message = $this->escalationDetector->criticalRecommendedAction();
        } else {
            $message = 'Thanks - your photo has been received and will be reviewed by the practice. This is not a discharge; if anything changes or you are worried, call the practice on (02) 9369 2800.';
        }

        $voters = [
            'primary' => [
                'model' => $primaryModel,
                'class' => $primary['class'],
                'confidence' => (float) $primary['confidence'],
                'rationale' => $primary['rationale'],
                'ok' => (bool) $primary['ok'],
            ],
            'secondary' => [
                'model' => $secondaryModel,
                'class' => $secondary['class'],
                'confidence' => (float) $secondary['confidence'],
                'rationale' => $secondary['rationale'],
                'ok' => (bool) $secondary['ok'],
            ],
        ];

        $verdict = [
            'class' => $decision['class'],
            'is_urgent' => $isUrgent,
            'reason' => $decision['reason'],
            'escalated_by' => $decision['escalated_by'],
            'unavailable' => (bool) $decision['unavailable'],
            'message' => $message,
            'voters' => $voters,
            'confidence_floor' => $floor,
            'trace_id' => $traceId,
            'event_id' => null,
        ];

        // Non-PHI trace/telemetry (self-hosted Langfuse only, if enabled).
        $this->trace($verdict, $visit, $document);

        // D5 audit: log a triage event on an urgent verdict.
        if ($isUrgent && $visit !== null) {
            $verdict['event_id'] = $this->logTriageEvent($verdict, $visit, $document);
        }

        return $verdict;
    }

    /**
     * Per-voter request options from config (e.g. reasoning_effort: none for the
     * secondary voter). A v4 tweak is a config edit.
     *
     * @return array<string, mixed>
     */
    private function voterOptions(string $which): array
    {
        return [
            'max_tokens' => (int) config('triage.max_tokens', 400),
            'temperature' => (float) config('triage.temperature', 0),
            'timeout' => (int) config('triage.request_timeout', 90),
            'retries' => (int) config('triage.retries', 2),
            'extra_params' => (array) config("triage.voter_params.{$which}.extra_params", []),
        ];
    }

    /**
     * Load the stored image as [base64, mimeType]. Reuses the DocumentAnalyzer
     * disk convention.
     *
     * @return array{0: string, 1: string}
     */
    private function loadImage(Document $document): array
    {
        $disk = config('filesystems.upload');
        $content = Storage::disk($disk)->get($document->file_path);
        $mimeType = Storage::disk($disk)->mimeType($document->file_path) ?: 'image/jpeg';

        $mediaType = match (true) {
            str_contains($mimeType, 'jpeg') || str_contains($mimeType, 'jpg') => 'image/jpeg',
            str_contains($mimeType, 'png') => 'image/png',
            str_contains($mimeType, 'gif') => 'image/gif',
            str_contains($mimeType, 'webp') => 'image/webp',
            str_contains($mimeType, 'heic') || str_contains($mimeType, 'heif') => 'image/jpeg',
            default => $mimeType,
        };

        return [base64_encode((string) $content), $mediaType];
    }

    /**
     * Write the D5 audit event. No PHI, no image bytes, no rationale free-text.
     */
    private function logTriageEvent(array $verdict, Visit $visit, ?Document $document): ?string
    {
        try {
            $event = TriageEvent::create([
                'visit_id' => $visit->id,
                'document_id' => $document?->id,
                'verdict' => $verdict['class'],
                'reason' => $verdict['reason'],
                'escalated_by' => $verdict['escalated_by'],
                'unavailable' => $verdict['unavailable'],
                'primary_model' => $verdict['voters']['primary']['model'],
                'primary_class' => $verdict['voters']['primary']['class'],
                'primary_confidence' => $verdict['voters']['primary']['confidence'],
                'primary_ok' => $verdict['voters']['primary']['ok'],
                'secondary_model' => $verdict['voters']['secondary']['model'],
                'secondary_class' => $verdict['voters']['secondary']['class'],
                'secondary_confidence' => $verdict['voters']['secondary']['confidence'],
                'secondary_ok' => $verdict['voters']['secondary']['ok'],
                'confidence_floor' => $verdict['confidence_floor'],
                'trace_id' => $verdict['trace_id'],
            ]);

            return $event->id;
        } catch (\Throwable $e) {
            // Never let audit-write failure block the patient-facing escalation.
            Log::error('Failed to write triage event', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The EXACT set of fields sent to Langfuse (self-hosted Railway only). This
     * list is the evidence for T1 "no PHI/image bytes in traces": every field is
     * a class label, a confidence number, a model alias, an OR-gate tag, or an
     * opaque uuid. No image, no rationale, no patient identifiers.
     *
     * @return array<string, mixed>
     */
    public function traceFields(array $verdict): array
    {
        return [
            'trace_id' => $verdict['trace_id'],
            'verdict' => $verdict['class'],
            'reason' => $verdict['reason'],
            'escalated_by' => $verdict['escalated_by'],
            'unavailable' => $verdict['unavailable'],
            'confidence_floor' => $verdict['confidence_floor'],
            'primary_model' => $verdict['voters']['primary']['model'],
            'primary_class' => $verdict['voters']['primary']['class'],
            'primary_confidence' => $verdict['voters']['primary']['confidence'],
            'primary_ok' => $verdict['voters']['primary']['ok'],
            'secondary_model' => $verdict['voters']['secondary']['model'],
            'secondary_class' => $verdict['voters']['secondary']['class'],
            'secondary_confidence' => $verdict['voters']['secondary']['confidence'],
            'secondary_ok' => $verdict['voters']['secondary']['ok'],
        ];
    }

    /**
     * Emit a trace to the self-hosted Langfuse (Railway) instance. Disabled by
     * default and a no-op unless config('triage.langfuse.enabled') and a
     * self-hosted host are set. cloud.langfuse.com is prohibited (#1393): a host
     * containing 'cloud.langfuse.com' is refused.
     */
    private function trace(array $verdict, ?Visit $visit, ?Document $document): void
    {
        if (! config('triage.langfuse.enabled')) {
            return;
        }
        $host = (string) config('triage.langfuse.host');
        if ($host === '' || str_contains($host, 'cloud.langfuse.com')) {
            Log::warning('Langfuse trace skipped: self-hosted host not configured (cloud.langfuse.com is prohibited, #1393)');

            return;
        }

        // Only the non-PHI field list is ever sent. Transport is intentionally
        // best-effort and must never block or throw into the triage path.
        try {
            $fields = $this->traceFields($verdict);
            Log::info('triage.trace', ['host' => $host, 'fields' => $fields]);
            // A concrete Langfuse HTTP ingestion call is wired here in the
            // observability deliverable; the field list above is the contract.
        } catch (\Throwable $e) {
            Log::warning('Langfuse trace failed', ['error' => $e->getMessage()]);
        }
    }
}
