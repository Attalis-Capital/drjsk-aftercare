#!/usr/bin/env python3
"""Truth-table tests for the sensitivity-first OR-gate consensus (no model calls).

Proves the D1/D2 safety behaviour: single-voter urgent, either low-confidence,
and either/both call failures all force `urgent`; only both-agree-confident
reaches `needs-review`. Run: python3 test_ensemble.py
"""
import ensemble as ens

FLOOR = 0.7
U = ens.URGENT
N = ens.NEEDS_REVIEW


def vote(cls, conf, ok=True):
    return (cls, conf, ok)


def check(name, primary, secondary, expected):
    got = ens.consensus(primary, secondary, FLOOR)["class"]
    status = "ok" if got == expected else "FAIL"
    print(f"  [{status}] {name}: got={got} expected={expected}")
    return got == expected


def main():
    results = []
    # Both agree needs-review, both confident -> needs-review (the only clear path)
    results.append(check("both_needs_review_confident",
                         vote(N, 0.9), vote(N, 0.9), N))
    # Either voter urgent -> urgent
    results.append(check("primary_urgent",
                         vote(U, 0.9), vote(N, 0.9), U))
    results.append(check("secondary_urgent",
                         vote(N, 0.9), vote(U, 0.9), U))
    # Either low confidence on a needs-review vote -> urgent
    results.append(check("primary_low_confidence",
                         vote(N, 0.5), vote(N, 0.9), U))
    results.append(check("secondary_low_confidence",
                         vote(N, 0.9), vote(N, 0.5), U))
    # Confidence exactly at floor is acceptable (>= floor)
    results.append(check("at_floor_is_confident",
                         vote(N, FLOOR), vote(N, FLOOR), N))
    # One call fails -> urgent
    results.append(check("primary_fail",
                         vote(None, 0.0, ok=False), vote(N, 0.9), U))
    results.append(check("secondary_fail",
                         vote(N, 0.9), vote(None, 0.0, ok=False), U))
    # Both calls fail -> urgent (triage unavailable path)
    results.append(check("both_fail_unavailable",
                         vote(None, 0.0, ok=False), vote(None, 0.0, ok=False), U))

    # Class enum is exactly two-valued (no discharge/normal exists)
    two_valued = set(ens.VALID_CLASSES) == {U, N} and len(ens.VALID_CLASSES) == 2
    print(f"  [{'ok' if two_valued else 'FAIL'}] class_enum_two_valued: {ens.VALID_CLASSES}")
    results.append(two_valued)

    passed = sum(1 for r in results if r)
    print(f"\n{passed}/{len(results)} checks passed")
    return 0 if passed == len(results) else 1


if __name__ == "__main__":
    import sys
    sys.exit(main())
