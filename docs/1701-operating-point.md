# Wound-photo triage: operating point (mission #1701)

Handoff note for James's pilot sign-off. This records the accepted default
operating point, an indicative v4 sketch, and how the point is changed. It does
not change any decision - the default is v3.

## v3 - the default, accepted pilot operating point

Measured on SurgWound-Bench (test split, N=137, 16 urgent positives; see
`eval/REPORT.md`):

- **Sensitivity 1.000 (16/16)** on the urgent class - the gated metric (target
  0.89), MET.
- **Specificity 0.149 (18/121)**, **PPV 0.134 (16/119)**, NPV 1.000.
- The ensemble escalates roughly **87%** of benchmark photos to review
  (119/137 verdicts were urgent).

This is a deliberate sensitivity-first trade: the cost of missing a
complication is far higher than the cost of the surgeon reviewing a photo that
turns out fine. The high escalate-to-review rate is a KNOWN clinical trade going
to James at handoff, not a defect to fix.

## Indicative v4 sketch (from the committed eval iteration/floor data)

The eval's prompt-iteration and confidence-floor sweep (`eval/REPORT.md`) bound
where a lower-escalation operating point would sit. These are INDICATIVE and
must not be read as precise measured operating points.

- The prompt-iteration log shows the trade space: v1 wording ("spreading
  redness") measured **0.875 sensitivity / 0.364 specificity**; v2 ("ANY redness
  escalates") measured **1.000 / 0.025**; v3 (the default) measured
  **1.000 / 0.149**. So at roughly **0.95-0.98 sensitivity**, specificity plausibly
  lands **between the v3 0.149 and the v1 0.364 points** - materially fewer
  false alarms, at the cost of occasionally deferring a borderline case to
  routine review. This is interpolation across the measured v1/v3 endpoints, not
  a measured v4 run.
- The confidence-floor sweep is flat across 0.5-0.8 (specificity 0.149
  throughout) and only degrades above 0.9, so the floor alone does not buy
  specificity at this operating region - a v4 gain comes from prompt wording,
  not the floor.

A precise v4 point requires one re-scored/refreshed eval run to confirm the
sensitivity/specificity pair before it is adopted.

## Caveat (must be stated at sign-off)

SurgWound-Bench base rates (16/137 urgent, general surgical wounds, partly
social-media provenance) will **NOT** match James's post-operative
plastic/reconstructive population. The live escalation rate in the pilot is
therefore **INDICATIVE ONLY** - it could be higher or lower than ~87% depending
on the real case mix and image quality. Skin-tone-stratified recall could not be
measured on this set (no skin-tone metadata); a de-identified DrJSK top-up is the
Phase 2 remedy.

**James picks the operating point at pilot sign-off. The default is v3.**

## How the operating point is changed (the v4 story)

Every value that defines the operating point lives in `config/triage.php` (and
matching env vars), not in code:

- voter model aliases (`triage.voters.primary`, `triage.voters.secondary`,
  configurable fallback `triage.voters.fallback`),
- confidence floor (`triage.confidence_floor`, default 0.7),
- thresholds and generation params (`triage.max_tokens`, `triage.temperature`),
- prompt selection (`triage.prompts.*`, pointing at the single-source
  `prompts/wound-triage-*.md`),
- Langfuse config (self-hosted Railway only).

Changing to a v4 point is therefore a **config edit plus a ~$5 eval re-run**
(`eval/run_eval.py` against the same config), with **zero code rework**. Re-run
the eval to re-confirm the sensitivity/specificity pair, update this note with
the measured numbers, and ship the config change.
