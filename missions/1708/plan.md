# Mission 1708 — Escalation clinical-safety gap (amended plan)

Relates to Attalis-Capital/attalis-missions#1700
Addresses pr-check revise on Attalis-Capital/drjsk-aftercare#2 (review 4615013472)

## Why this plan is amended (stale-premise reconciliation)

The mission was authored against HEAD `f09588a` of branch
`feat/1700-tasks-2-5-clinical-content` (PR #2), where `EscalationDetector::evaluate()`
returned `is_urgent:false` unconditionally and `CRITICAL_KEYWORDS` omitted 3 of the 5
surgeon-confirmed URGENT triggers.

**That state no longer exists.** Verified on `main` at the start of this mission:

- **PR #2 is MERGED** (commit `d60c3d6`). Its DO-NOT-MERGE gate is moot; there is no
  open PR #2 branch to "update in place". The hard rule "do not merge #2" is honoured
  trivially — it is already merged and this mission does not touch it.
- `EscalationDetector` on `main` already fires `is_urgent:true / severity:critical` for
  all five surgeon triggers via the keyword fast-path. Task 1's core safety gap
  (haematoma, dehiscence) is **already closed**.
- `EscalationDetectorTest` on `main` already has: one data-provider case per trigger, the
  flap-colour post-discharge negative case, and a structural prompt/code coverage test.
  Task 2's core is **already present**.
- Task 3 (rebase the branch so PHP 8.4 CI runs) is moot: `main` carries `.github/workflows/ci.yml`
  (PHP 8.4, `composer test`) and this mission's new PR targets `main`, so CI runs on it.
- Task 4 premise is stale: `resources/js/views/LegalPage.vue` no longer references
  `privacy@drjsk.com.au` / `hello@drjsk.com.au`; it now uses a single `info@drjsk.com.au`
  (consolidated by #1718). See Task 4 note below.

## What remains genuinely in scope

One real, mission-and-council-mandated defect survives in `main`: **fever is detected as a
bare `'fever'` substring keyword** (plus brittle literal tokens `'38.5'`, `'temperature of 39'`,
`'39 degrees'`, …). This:

1. **Over-triggers on negation** — "I have *no fever*" / "worried about fever but my temp is
   normal" both contain the substring `fever` and are classified `critical` → call-the-practice /
   000. False critical escalation causes patient alarm and alert fatigue.
2. **Directly violates Task 1**: "Fever is a NUMERIC threshold, not a substring — parse a
   temperature from the message and compare; do not add 'fever' as a bare keyword."
3. **Fails Council P1** (explicit temperature-parsing contract) and **Council P2** (named
   `FEVER_THRESHOLD_C` constant referencing the prompt).

## Scoped changes (this PR)

Scope is strictly `EscalationDetector` + its test (mission hard rule). New branch
`feat/1708-fever-numeric-parse` off `main`; **new PR, do NOT merge** (Anika-authored — John
reviews).

1. **`EscalationDetector`**
   - Remove bare `'fever'` and the brittle literal temperature tokens from `CRITICAL_KEYWORDS`.
   - Add `public const FEVER_THRESHOLD_C = 38.5;` (mirrors "Fever above 38.5C" in the prompt;
     mission Task 1 uses `>= 38.5`).
   - Add a documented numeric temperature parser (`detectFever()`), wired into `checkCriticalKeywords()`,
     that fires `is_urgent:true / severity:critical` with `CRITICAL_RECOMMENDED_ACTION`.
   - The other four triggers (breathing/chest, haematoma/swelling, bleeding, dehiscence) are
     unchanged — they remain phrase keywords.

2. **Temperature-parsing contract** (documented in the method docblock, per Council P1):
   - Accepted: `38.5°C`, `38.5 C`, `38.5C`, `38.5 Celsius`, `38.5 degrees`, `temperature of 39`,
     bare `39`; Fahrenheit `101.5F` / `101 Fahrenheit` / `101°F` (converted to C).
   - Unit assumption: **bare number = Celsius**; explicit `F`/`Fahrenheit` converts.
   - Range validation: only plausible human body temperatures (30–45 °C after normalisation) count;
     this discards non-temperature numbers ("101 stitches") without a keyword.
   - Fail-safe: a bare number in the Fahrenheit-fever range that appears alongside a fever/temp
     context word is interpreted as Fahrenheit and compared (leans toward escalation), rather than
     silently dropped.
   - Threshold is `>=` (mission Task 1). Boundary `38.4999` → NOT urgent; `38.5` → urgent.

3. **Tests** (`EscalationDetectorTest`)
   - Keep the five per-trigger and flap-colour cases.
   - Add ≥4 temperature input-variant cases incl. Fahrenheit and boundary `38.4999` (Council P1).
   - Add a "no fever" / normal-temperature negative case (the over-trigger the refactor fixes).
   - Update the structural coverage test: the `fever` trigger is now validated via the
     `FEVER_THRESHOLD_C` constant + parser behaviour, not a keyword token (Council P2).

## PAUSE (needs John + treating clinician)

Post ONE question on the PR and stop: keep escalation **keyword-only** (all 5 triggers mirrored,
fever numeric — this PR), OR re-enable `aiEvaluate()` as a slow-path (adds 5–15 s latency before
the first chat token). Task 1 lands regardless (strictly safer either way). `aiEvaluate()` is left
intact and unwired pending the decision.

## Task 4 note (Council P3 — do not enumerate contact infra publicly)

`LegalPage.vue` uses `info@drjsk.com.au` (not the `privacy@`/`hello@` addresses the mission named;
those were consolidated in #1718). Mailbox MX/routability is external infrastructure and is not
probed from here. Definition of done for the failure path: routability confirmation is owned by
John (practice ops) as a tracked pilot sign-off item, not adjudicated in this PR thread and not
enumerated publicly. Flagged as **unresolved — needs owner confirmation**.
