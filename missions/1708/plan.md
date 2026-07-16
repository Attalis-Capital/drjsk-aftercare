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

## Amendment — PR #11 revision (shinny77, pr-check v2, 7 Jul 2026)

The numeric-only parser was accepted as a correct Task-1 fix, but the review flagged a
clinical-safety regression it introduced: requiring a *parseable number* meant an
**affirmative unquantified** fever report ("I have a fever", "feeling feverish") no longer
escalated. For a post-operative red flag that is the wrong direction — a missed
infection/sepsis far outweighs a false alarm. The fix is **not** to re-add the bare
`'fever'` substring (that reintroduces the "no fever" over-trigger); it is an affirmative,
negation-aware qualitative trigger sitting *beside* the numeric path.

Added in this revision (scope held to `EscalationDetector` + its test):

1. **`detectQualitativeFever()`** — matches affirmative fever *constructions*
   (`have/has/got/running a fever`, `feverish` + common misspellings, `burning up`,
   `high temperature`, `feel hot`), never the bare `fever` noun. This is what keeps
   "worried about a fever earlier but I feel fine now" (existing negative case) and
   "denies fever" non-escalating while "I have a fever" escalates.
2. **`isFeverNegatedBefore()`** — rejects any affirmative match preceded (within 30 chars)
   by a negation cue (`no|not|never|without|denies|deny|denied|nil|negative|n't`), so
   "I don't have a fever" does not escalate. Short cues are word-bounded so "now" ≠ "no".
3. **Measurement governs** — the qualitative path fires only when *no* temperature was
   parsed. If the patient gave a reading, the numeric `>= 38.5C` comparison decides; a
   measured-but-normal value (e.g. "feverish but only 37.2C") does not escalate.
4. **`FEVER_QUALITATIVE_RECOMMENDED_ACTION`** — escalates (practice + 000) *and* prompts
   "Have you taken your temperature?"; never gated on the patient owning a thermometer.
5. **Prompt** — one behavioural rule added; the authoritative 5-item URGENT list is
   unchanged (still surgeon-confirmed), so the structural test's count holds.
6. **Tests** — qualitative escalate / negation-guard / measurement-governs rows added to
   `feverTemperatureCases`, plus a temperature-prompt assertion.

**Deliberately deferred (review point #5, severe features).** The 000 hard-escalate path
is *preserved*: breathing difficulty and severe headache remain keyword triggers and every
critical escalation (including the new qualitative one) carries the 000 fallback. Adding
*new* triggers for confusion / stiff neck / rash is held out of this "one concern only" PR —
those need clinical wording sign-off to avoid over-triggering (e.g. "confused about my
meds", a minor "rash"). Flagged for a follow-up. See the PR reply.

**Note:** local environment has no PHP/Herd runtime, so tests were not run locally; the
repo CI workflow (`.github/workflows/ci.yml`, PHP 8.4, `composer test`) is the verification
gate on the PR.

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
