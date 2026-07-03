<?php

namespace App\Services\AI;

/**
 * Two-voter sensitivity-first OR-gate consensus for wound triage (mission #1701,
 * decisions D1/D2). This is a DIRECT PHP mirror of eval/ensemble.py::consensus -
 * do not invent a variant.
 *
 * OR-gate to escalate. The ensemble verdict is `urgent` if ANY of:
 *   - either voter returns class `urgent`
 *   - either voter's confidence is below the tunable floor (on a needs-review vote)
 *   - either voter's call failed / returned unusable output (ok=false)
 * The ensemble verdict is `needs-review` ONLY when BOTH voters agree
 * `needs-review` AND both are at or above the confidence floor.
 * Both calls fail -> `urgent` (triage unavailable path).
 */
class TriageConsensus
{
    /**
     * Apply the OR-gate. Each voter array is the normalised vote returned by
     * LiteLlmMultimodalClient::classify(): {class, confidence, ok, ...}.
     *
     * @param  array{class: ?string, confidence: float, ok: bool}  $primary
     * @param  array{class: ?string, confidence: float, ok: bool}  $secondary
     * @return array{class: string, reason: string, escalated_by: array<int,string>, unavailable: bool}
     */
    public function decide(array $primary, array $secondary, float $confidenceFloor): array
    {
        $pOk = (bool) ($primary['ok'] ?? false);
        $sOk = (bool) ($secondary['ok'] ?? false);
        $pCls = $primary['class'] ?? null;
        $sCls = $secondary['class'] ?? null;
        $pConf = (float) ($primary['confidence'] ?? 0.0);
        $sConf = (float) ($secondary['confidence'] ?? 0.0);

        $urgent = TriageVerdict::Urgent->value;
        $needsReview = TriageVerdict::NeedsReview->value;

        // Both failed -> urgent, triage unavailable path.
        if (! $pOk && ! $sOk) {
            return [
                'class' => $urgent,
                'reason' => 'both_calls_failed_unavailable',
                'escalated_by' => ['primary_fail', 'secondary_fail'],
                'unavailable' => true,
            ];
        }

        $escalatedBy = [];
        if (! $pOk) {
            $escalatedBy[] = 'primary_fail';
        }
        if (! $sOk) {
            $escalatedBy[] = 'secondary_fail';
        }
        if ($pOk && $pCls === $urgent) {
            $escalatedBy[] = 'primary_urgent';
        }
        if ($sOk && $sCls === $urgent) {
            $escalatedBy[] = 'secondary_urgent';
        }
        if ($pOk && $pCls === $needsReview && $pConf < $confidenceFloor) {
            $escalatedBy[] = 'primary_low_confidence';
        }
        if ($sOk && $sCls === $needsReview && $sConf < $confidenceFloor) {
            $escalatedBy[] = 'secondary_low_confidence';
        }

        if (! empty($escalatedBy)) {
            return [
                'class' => $urgent,
                'reason' => 'or_gate_escalation',
                'escalated_by' => $escalatedBy,
                'unavailable' => false,
            ];
        }

        // Both usable, both needs-review, both at/above floor -> needs-review.
        return [
            'class' => $needsReview,
            'reason' => 'both_agree_needs_review_confident',
            'escalated_by' => [],
            'unavailable' => false,
        ];
    }
}
