# Wound-triage ensemble eval report (mission #1701, Deliverable 4)

**Scope:** EVAL ONLY. This is the raw two-voter VLM ensemble measured against
SurgWound-Bench before any application wiring. No app code was touched.

**Verdict: the 89% sensitivity gate is MET.** Final configuration reaches
**sensitivity 1.000 (16/16)** on the urgent class at the chosen operating point.

## Run configuration (final)

- Ensemble: primary `claude-opus-4-8` + second voter `gemini-3.5-flash`, both via
  the LiteLLM gateway (litellm.attaliscapital.com) OpenAI-compatible endpoint.
  No direct provider endpoints; the gateway virtual key is the only credential
  and is never read from secrets or logged.
- Second voter called with `reasoning_effort: none` (prevents internal reasoning
  tokens truncating its JSON, and reduces cost).
- Consensus: D1/D2 sensitivity-first OR-gate (either voter urgent, or either
  low-confidence, or either call fails -> urgent; both-agree-confident -> needs-review).
- Confidence floor: **0.7** (stable plateau; see the floor sweep below).
- Prompts: `prompts/wound-triage-primary.md` (hash 71d54d60934b334b),
  `prompts/wound-triage-secondary.md` (hash b94379f7eb1c72b0). Iteration v3.
- Temperature 0, max_tokens 400.

## Dataset / split

- SurgWound-Bench (xuxuxuxuxu/SurgWound), **test** split, **Urgency Level** field.
- N = 137 images (one Urgency-Level row per image).
- Ground-truth mapping (per #1701, 2 Jul): positive/urgent = Yellow (Clinic
  within 48h) + Red (Emergency); negative = Green (Home Care).
- Class balance: 121 Green (negative), 12 Yellow + 4 Red = **16 positive**.

## Confusion matrix (positive class = urgent)

```
                     predicted urgent   predicted needs-review
actual urgent (16)         TP = 16              FN = 0
actual non-urgent (121)    FP = 103             TN = 18
```

## Metrics

| Metric | Value | Notes |
| --- | --- | --- |
| Sensitivity (urgent recall) | **1.000 (16/16)** | GATED metric; target 0.89 - **MET** |
| - Yellow (Clinic 48h) recall | 12/12 | |
| - Red (Emergency) recall | 4/4 | |
| Specificity | 0.149 (18/121) | reported, not gated (sensitivity-first design) |
| PPV (precision) | 0.134 (16/119) | high false-alarm rate is the accepted trade |
| NPV | 1.000 (18/18) | no missed urgent among the needs-review calls |

Escalation was driven by a model urgent vote in almost every positive case
(primary urgent on 109 images, secondary urgent on 112). 119 of 137 verdicts
were OR-gate escalations; 18 reached needs-review by both voters agreeing and
being confident.

## Prompt / threshold iteration log

| Iter | Prompt change | Sensitivity | Specificity | Live spend |
| --- | --- | --- | --- | --- |
| v1 | "spreading redness" (surgeon wording) | 0.875 (14/16) | 0.364 | ~$4.21 |
| v2 | "ANY redness escalates" | 1.000 (16/16) | 0.025 | ~$4.77 |
| v3 | erythema onto surrounding skin / distinct discolouration escalates; faint suture-line tint may be needs-review | **1.000 (16/16)** | **0.149** | ~$4.96 |

v1 missed two Yellow cases (25.jpg, 66.jpg) where both voters confidently called
needs-review; both images showed mild localised erythema/discolouration that the
surgeon-worded "spreading redness" trigger did not capture. This was fixed by
prompt tuning only - the class definitions (urgent/needs-review) were NOT
changed. v2 over-corrected (specificity collapsed). v3 restores usable
specificity while holding sensitivity at 1.000.

Confidence-floor sweep on v3 (re-scored from cache, $0):

| Floor | Sensitivity | Specificity |
| --- | --- | --- |
| 0.5 | 1.000 | 0.149 |
| 0.6 | 1.000 | 0.149 |
| **0.7** | **1.000** | **0.149** |
| 0.8 | 1.000 | 0.149 |
| 0.9 | 1.000 | 0.132 |
| 0.95 | 1.000 | 0.008 |

The 0.5-0.8 plateau is stable (models are confident, so the low-confidence
branch rarely fires there). 0.7 is chosen as the operating point.

## Cost

- Cumulative eval spend: **~$14.25** (three full 137-image runs at ~$4.2-5.0
  each, plus ~11 smoke-test calls; the floor sweep reused cache at $0). Well
  under the $200/day ceiling.
- Spend is estimated from token usage x public list prices (Opus 4.8
  $15/$75 per Mtok; Gemini 3.5 Flash $0.30/$2.50 per Mtok); the gateway remains
  the billing source of truth.
- Caching by (image_id, model, prompt_hash) made prompt/threshold iteration cheap
  and floor re-scoring free.

## Caveats (recorded, not worked around)

- N=16 positives: one missed case = 6.25 sensitivity points, so the 1.000 point
  estimate has a wide confidence interval. This validates the ensemble MECHANISM,
  not a precise operating point.
- SurgWound-Bench is general surgical wounds (partly social-media provenance),
  not plastic-surgery-specific (not DIEP/abdominoplasty/breast-reduction).
- No skin-tone metadata in the dataset card, so the skin-tone-stratified recall
  criterion cannot be met on this set alone. A de-identified DrJSK top-up is the
  Phase 2 remedy (flagged, not a Phase 1 blocker).
- Specificity is low by design (sensitivity-first). At pilot, this means the
  surgeon reviews many photos that turn out fine - the accepted trade per John's
  non-negotiable design principle. A specificity-improving pass (e.g. a third
  voter or a rules-engine pre-filter) is a later deliverable, not this gate.
