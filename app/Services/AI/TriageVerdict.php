<?php

namespace App\Services\AI;

/**
 * The two-valued wound-triage output enum (mission #1701, decision D3).
 *
 * There are EXACTLY two classes: urgent and needs-review. There is deliberately
 * NO discharge/normal/fine/healthy class anywhere. This mirrors
 * eval/ensemble.py (URGENT, NEEDS_REVIEW, VALID_CLASSES) so the app and the eval
 * cannot diverge. TriageServiceTest asserts the enum is two-valued.
 */
enum TriageVerdict: string
{
    case Urgent = 'urgent';
    case NeedsReview = 'needs-review';

    /**
     * The complete, closed set of valid class strings. Used by the multimodal
     * client to reject any off-schema class (fail-toward-escalation) and by the
     * consensus gate. Two entries only, by design.
     *
     * @var array<int, string>
     */
    public const VALID_CLASSES = ['urgent', 'needs-review'];

    public function isUrgent(): bool
    {
        return $this === self::Urgent;
    }
}
