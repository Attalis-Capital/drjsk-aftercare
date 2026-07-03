<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\Log;

/**
 * Mission #1718 B1: close the clinical loop.
 *
 * Persists a doctor-visible alert (a Notification row) whenever an urgent
 * triage verdict or a critical chat escalation occurs. These are surfaced,
 * pinned at the top, in the DoctorController alerts payload.
 *
 * This service performs UI/surfacing only. It does NOT change any triage or
 * escalation logic - it is called by the CONSUMING code (the triage caller and
 * the chat controller) once a decision has already been made upstream.
 *
 * No email is sent here (that remains mission #1710); this is in-dashboard
 * surfacing only. An alert-write failure must never block the patient-facing
 * response, so every failure is caught and logged.
 */
class ClinicalAlertNotifier
{
    public const TYPE_URGENT_TRIAGE = 'urgent_triage';

    public const TYPE_CRITICAL_CHAT = 'critical_chat';

    /**
     * Create a doctor-visible alert for an urgent wound-photo triage verdict.
     */
    public function urgentTriage(Visit $visit, ?string $documentId = null, ?string $reason = null): ?Notification
    {
        return $this->create(
            $visit,
            self::TYPE_URGENT_TRIAGE,
            'Urgent wound-photo triage',
            'A patient wound photo was triaged as urgent and needs clinical review.',
            [
                'document_id' => $documentId,
                'triage_reason' => $reason,
            ],
        );
    }

    /**
     * Create a doctor-visible alert for a critical chat escalation.
     */
    public function criticalChat(Visit $visit, ?string $reason = null): ?Notification
    {
        return $this->create(
            $visit,
            self::TYPE_CRITICAL_CHAT,
            'Critical chat escalation',
            'A patient reported a critical symptom in chat and was advised to call the practice.',
            [
                'escalation_reason' => $reason,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function create(Visit $visit, string $type, string $title, string $body, array $data): ?Notification
    {
        try {
            $doctorUserId = $this->resolveDoctorUserId($visit);
            if ($doctorUserId === null) {
                Log::warning('Clinical alert not created: no doctor user for visit', [
                    'visit_id' => $visit->id,
                    'type' => $type,
                ]);

                return null;
            }

            $patient = $visit->patient;
            $patientName = $patient
                ? trim($patient->first_name.' '.$patient->last_name)
                : null;

            return Notification::create([
                'user_id' => $doctorUserId,
                'visit_id' => $visit->id,
                'type' => $type,
                'severity' => 'high',
                'title' => $title,
                'body' => $body,
                'data' => array_merge($data, [
                    'patient_id' => $visit->patient_id,
                    'patient_name' => $patientName,
                ]),
            ]);
        } catch (\Throwable $e) {
            // Never let an alert-write failure block the patient-facing response.
            Log::error('Failed to create clinical alert', [
                'visit_id' => $visit->id ?? null,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Resolve the doctor User who should receive the alert: the User linked to
     * the visit's practitioner.
     */
    private function resolveDoctorUserId(Visit $visit): ?string
    {
        if ($visit->practitioner_id === null) {
            return null;
        }

        return User::query()
            ->where('practitioner_id', $visit->practitioner_id)
            ->whereIn('role', ['doctor', 'admin'])
            ->value('id');
    }
}
