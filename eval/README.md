# Wound-triage eval harness (mission #1701, Deliverable 4)

**EVAL ONLY.** This harness measures the raw two-voter VLM ensemble against
SurgWound-Bench BEFORE any application wiring. It does not touch the Laravel/Vue
app. It exists to answer one question: does the ensemble reach the 89% WISDOM
sensitivity target on the urgent class?

## What it does

1. `fetch_dataset.py` - pulls the SurgWound-Bench **test** split via the public
   Hugging Face datasets-server, keeps only the **Urgency Level** rows (one per
   image), decodes the embedded image, and writes a manifest under
   `eval/.cache/` (gitignored - no dataset images are committed).
2. `gateway_client.py` - LiteLLM-gateway-only multimodal client. Every call
   routes through `litellm.attaliscapital.com` via the OpenAI-compatible
   `/v1/chat/completions` endpoint. No direct provider SDKs/endpoints. No secrets
   are read or logged; the gateway virtual key is sourced from the environment at
   call time. Responses are cached on disk keyed by `(image_id, model,
   prompt_hash)` so prompt/threshold iteration re-runs are free, and USD spend is
   tracked against the $200/day ceiling.
3. `ensemble.py` - the D1/D2 sensitivity-first OR-gate consensus (see below).
4. `run_eval.py` - runs the ensemble over the manifest, builds the confusion
   matrix, and reports sensitivity/specificity against the 0.89 target.

## Ground-truth mapping

SurgWound-Bench **Urgency Level** field, per the #1701 validation-set decision:

- Positive (escalate / `urgent`): **Yellow** (Clinic within 48h) + **Red**
  (Emergency).
- Negative (`needs-review`): **Green** (Home Care).

Sensitivity = recall on the positive (urgent) class. It is the gated metric; the
0.89 WISDOM target applies to it. Specificity is reported, not gated (the design
is sensitivity-first: false alarms are acceptable, missed complications are not).

## Ensemble consensus (D1/D2 - implemented exactly in ensemble.py)

OR-gate to escalate. Verdict is `urgent` if ANY of: either voter returns
`urgent`; either voter's `needs-review` confidence is below the tunable floor;
either call fails/returns unusable output. Verdict is `needs-review` ONLY when
BOTH voters agree `needs-review` AND both are at/above the floor. Both calls
fail -> `urgent` (triage-unavailable path). Output classes are exactly two:
`urgent` / `needs-review`. No discharge/normal class exists.

## Models (via LiteLLM aliases only)

- Primary voter: `claude-opus-4-8`
- Second voter: `gemini-3.5-flash` (called with `reasoning_effort: none` so its
  JSON is not truncated by internal reasoning tokens)

Prompts live in `../prompts/wound-triage-primary.md` and
`../prompts/wound-triage-secondary.md` (sensitivity-first, the surgeon's URGENT
triggers as visual criteria, explicit "uncertainty escalates").

## Running

```
python3 -m venv venv && ./venv/bin/pip install pillow
export ANTHROPIC_BASE_URL=https://litellm.attaliscapital.com
export ANTHROPIC_API_KEY=<litellm gateway virtual key>   # never a provider key
./venv/bin/python fetch_dataset.py
./venv/bin/python run_eval.py --confidence-floor 0.7
```

The result JSON (confusion matrix, metrics, spend, per-image records) is written
to `eval/.cache/` for full runs; the committed evidence artefact is
`eval/REPORT.md`.

## Dataset attribution (CC-BY-SA-4.0)

SurgWound-Bench: xuxuxuxuxu/SurgWound, Ohio State University Wexner Medical
Center, published Aug 2025. Licensed CC-BY-SA-4.0. Images are used here only for
local validation and are NOT redistributed/committed. Any derived artefact
shares alike under CC-BY-SA-4.0.

## Caveats (record, do not work around)

- SurgWound-Bench is general surgical wounds sourced partly from social media and
  surgeon education accounts, NOT plastic-surgery-specific (not DIEP/
  abdominoplasty/breast-reduction). It validates the ensemble mechanism, not the
  exact plastic-surgery case mix.
- Image quality varies (social-media provenance) - this exercises the eventual
  rules-engine pre-filter but is not clean clinical photography.
- The positive class is small (16 of 137 images: 12 Yellow + 4 Red). With N=16
  positives, one missed case moves sensitivity by 6.25 points, so the point
  estimate has a wide confidence interval. Treat the number as a mechanism check,
  not a precise operating point.
- Skin-tone metadata is not exposed by the dataset card, so the skin-tone-
  stratified recall criterion cannot be met on this set alone. A de-identified
  DrJSK top-up is the Phase 2 remedy (flagged, not a Phase 1 blocker).
