#!/usr/bin/env python3
"""Two-voter sensitivity-first ensemble consensus for wound triage (mission #1701).

Implements the D1/D2 gate semantics exactly:

  OR-gate to escalate. The ensemble verdict is `urgent` if ANY of:
    - either voter returns class `urgent`
    - either voter's confidence is below the tunable floor
    - either voter's call failed / returned unparseable output (unavailable path)
  The ensemble verdict is `needs-review` ONLY when BOTH voters agree
  `needs-review` AND both are at or above the confidence floor.
  Both calls fail -> `urgent` (triage unavailable path).

Output classes are exactly two: `urgent` / `needs-review`. No discharge/normal
class exists anywhere in this module.
"""

URGENT = "urgent"
NEEDS_REVIEW = "needs-review"
VALID_CLASSES = (URGENT, NEEDS_REVIEW)


def normalise_vote(parsed, meta):
    """Turn a raw model result into (class, confidence, ok).

    ok=False means the call failed or the output was unusable -> treated as
    a fail-toward-escalation signal by the consensus function.
    """
    if meta and meta.get("error") and parsed is None:
        return (None, 0.0, False)
    if not isinstance(parsed, dict):
        return (None, 0.0, False)
    cls = str(parsed.get("class", "")).strip().lower()
    if cls not in VALID_CLASSES:
        # Any off-schema class is unusable -> escalate.
        return (None, 0.0, False)
    try:
        conf = float(parsed.get("confidence", 0.0))
    except (TypeError, ValueError):
        conf = 0.0
    conf = max(0.0, min(1.0, conf))
    return (cls, conf, True)


def consensus(primary, secondary, confidence_floor):
    """Apply the OR-gate. primary/secondary are (class, confidence, ok) tuples.

    Returns dict: {class, reason, primary, secondary, escalated_by}.
    """
    p_cls, p_conf, p_ok = primary
    s_cls, s_conf, s_ok = secondary

    reasons = []

    both_failed = (not p_ok) and (not s_ok)
    if both_failed:
        return {
            "class": URGENT,
            "reason": "both_calls_failed_unavailable",
            "primary": primary,
            "secondary": secondary,
            "escalated_by": ["primary_fail", "secondary_fail"],
        }

    escalated_by = []
    if not p_ok:
        escalated_by.append("primary_fail")
    if not s_ok:
        escalated_by.append("secondary_fail")
    if p_ok and p_cls == URGENT:
        escalated_by.append("primary_urgent")
    if s_ok and s_cls == URGENT:
        escalated_by.append("secondary_urgent")
    if p_ok and p_cls == NEEDS_REVIEW and p_conf < confidence_floor:
        escalated_by.append("primary_low_confidence")
    if s_ok and s_cls == NEEDS_REVIEW and s_conf < confidence_floor:
        escalated_by.append("secondary_low_confidence")

    if escalated_by:
        return {
            "class": URGENT,
            "reason": "or_gate_escalation",
            "primary": primary,
            "secondary": secondary,
            "escalated_by": escalated_by,
        }

    # Both usable, both needs-review, both at/above floor -> needs-review.
    return {
        "class": NEEDS_REVIEW,
        "reason": "both_agree_needs_review_confident",
        "primary": primary,
        "secondary": secondary,
        "escalated_by": [],
    }
