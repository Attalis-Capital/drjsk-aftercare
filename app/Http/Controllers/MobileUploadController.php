<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeDocumentJob;
use App\Models\Document;
use App\Models\UploadToken;
use App\Services\AI\TriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class MobileUploadController extends Controller
{
    public function __construct(
        private TriageService $triageService,
    ) {}

    public function show(string $token): View
    {
        $uploadToken = UploadToken::where('token', $token)->firstOrFail();

        if (! $uploadToken->isValid()) {
            abort(410, 'This upload link has expired or has already been used.');
        }

        return view('upload', [
            'token' => $token,
            'visitReason' => $uploadToken->visit->reason_for_visit,
            'expiresAt' => $uploadToken->expires_at->toIso8601String(),
            // D6 consent: whether wound-photo triage is enabled for this pilot.
            'triageEnabled' => (bool) config('triage.enabled', true),
        ]);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $uploadToken = UploadToken::where('token', $token)->firstOrFail();

        if (! $uploadToken->isValid()) {
            return response()->json([
                'error' => 'This upload link has expired or has already been used.',
            ], 410);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,pdf,heic,heif'],
            // wound_photo routes into the #1701 triage ensemble.
            'document_type' => ['nullable', 'string', 'in:ecg,imaging,lab_result,photo,wound_photo,other'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $visit = $uploadToken->visit;

        $contentType = match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif']) => 'image',
            $extension === 'pdf' => 'pdf',
            default => 'other',
        };

        $path = $file->store("documents/{$visit->id}", config('filesystems.upload'));

        $document = Document::create([
            'fhir_document_reference_id' => 'DocumentReference/'.Str::uuid(),
            'patient_id' => $visit->patient_id,
            'visit_id' => $visit->id,
            'title' => $request->input('title') ?: $file->getClientOriginalName(),
            'document_type' => $request->input('document_type', 'photo'),
            'content_type' => $contentType,
            'file_path' => $path,
            'file_size' => $file->getSize(),
            'file_hash' => hash_file('sha256', $file->getRealPath()),
            'status' => 'current',
            'document_date' => now()->toDateString(),
            'confidentiality_level' => 'M',
            'created_by' => $uploadToken->created_by,
        ]);

        // Patient-initiated wound photos (mission #1701) route into the two-voter
        // triage ensemble instead of the generic document analyser. The verdict is
        // surfaced in-chat on the upload screen (D5 alert surfacing v1). Any
        // failure fails toward escalation and never blocks the upload.
        $triage = null;
        $isWoundPhoto = $request->input('document_type') === config('triage.triage_document_type')
            && $contentType === 'image'
            && config('triage.enabled', true);

        if ($isWoundPhoto) {
            try {
                $triage = $this->triageService->triageDocument($document);
            } catch (\Throwable $e) {
                report($e);
                // Fail toward escalation: if triage itself errors, tell the
                // patient to call the practice rather than silently pass.
                $triage = [
                    'class' => 'urgent',
                    'is_urgent' => true,
                    'reason' => 'triage_error_unavailable',
                    'unavailable' => true,
                    'message' => 'We could not automatically review your photo right now. To be safe, please call the practice on (02) 9369 2800; in an emergency call 000. Do not wait.',
                ];
            }
        } elseif (in_array($contentType, ['image', 'pdf'])) {
            AnalyzeDocumentJob::dispatch($document);
        }

        $uploadToken->markUsed($document);

        $payload = ['data' => $document];
        if ($triage !== null) {
            // Only surface the patient-facing fields; no per-voter rationale or
            // trace internals in the HTTP response.
            $payload['triage'] = [
                'class' => $triage['class'],
                'is_urgent' => $triage['is_urgent'],
                'message' => $triage['message'],
            ];
        }

        return response()->json($payload, 201);
    }
}
