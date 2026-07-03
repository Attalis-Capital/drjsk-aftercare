#!/usr/bin/env python3
"""SurgWound-Bench eval runner for the wound-triage ensemble (mission #1701).

EVAL ONLY - this does not wire the application. It runs the RAW ensemble against
the SurgWound-Bench test split (Urgency Level field) via the LiteLLM gateway and
reports a confusion matrix + sensitivity/specificity against the 89% target.

Ground-truth mapping (per attalis-missions#1701 validation-set comment, 2 Jul):
  Urgency Level -> escalate-positive class = {Yellow (Clinic within 48h), Red
  (Emergency)}; negative = {Green (Home Care)}.
  Sensitivity (recall on the positive class) is the gated metric; the 89% WISDOM
  target applies to it. Specificity is reported, not gated.

Ensemble predicts `urgent` (== escalate-positive) or `needs-review` (== negative
prediction). Sensitivity = TP / (TP + FN) over the positive (urgent) class.

Cost discipline: responses cached by (image_id, model, prompt_hash); cumulative
USD spend tracked and printed; run halts if spend crosses the $150 soft ceiling.
"""
import argparse
import json
import os
import sys

import gateway_client as gw
import ensemble as ens

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
MANIFEST = os.path.join(HERE, ".cache", "urgency_manifest.json")

SPEND_SOFT_CEILING = 150.0  # USD - checkpoint + halt rather than push to $200.

USER_TEXT = (
    "Assess this single post-surgical wound photograph and return the JSON "
    "object exactly as specified. Remember: uncertainty escalates; output only "
    "urgent or needs-review; never state the wound is normal."
)


def load_prompt(path):
    with open(path) as f:
        return f.read()


def ground_truth_positive(answer):
    a = (answer or "").strip().lower()
    return a.startswith("clinic") or a.startswith("emergency")  # Yellow or Red


def run(args):
    if not os.path.exists(MANIFEST):
        print("ERROR: manifest missing - run fetch_dataset.py first", file=sys.stderr)
        return 2
    manifest = json.load(open(MANIFEST))
    if args.limit:
        manifest = manifest[: args.limit]

    primary_prompt = load_prompt(os.path.join(REPO, "prompts", "wound-triage-primary.md"))
    secondary_prompt = load_prompt(os.path.join(REPO, "prompts", "wound-triage-secondary.md"))

    spend = gw.SpendTracker()
    floor = args.confidence_floor

    records = []
    # Confusion matrix cells (positive class = urgent).
    tp = fp = tn = fn = 0

    for i, m in enumerate(manifest):
        image_id = m["image_name"]
        image_path = os.path.join(HERE, m["image_path"])
        gt_pos = ground_truth_positive(m["answer"])

        p_parsed, p_meta = gw.classify(
            image_id, image_path, args.primary_model, primary_prompt, USER_TEXT, spend,
            max_tokens=args.max_tokens, timeout=args.timeout)
        # Gemini 3.5 Flash emits internal reasoning tokens that truncate the JSON
        # under a tight cap; reasoning_effort=none yields clean, complete JSON and
        # is cheaper. Applied only to the secondary voter.
        s_extra = {"reasoning_effort": "none"} if "gemini" in args.secondary_model else None
        s_parsed, s_meta = gw.classify(
            image_id, image_path, args.secondary_model, secondary_prompt, USER_TEXT, spend,
            max_tokens=args.max_tokens, timeout=args.timeout, extra_params=s_extra)

        p_vote = ens.normalise_vote(p_parsed, p_meta)
        s_vote = ens.normalise_vote(s_parsed, s_meta)
        verdict = ens.consensus(p_vote, s_vote, floor)
        pred_pos = verdict["class"] == ens.URGENT

        if gt_pos and pred_pos:
            tp += 1
        elif gt_pos and not pred_pos:
            fn += 1
        elif (not gt_pos) and pred_pos:
            fp += 1
        else:
            tn += 1

        records.append({
            "image_name": image_id,
            "gt_answer": m["answer"],
            "gt_positive": gt_pos,
            "pred_class": verdict["class"],
            "pred_positive": pred_pos,
            "reason": verdict["reason"],
            "escalated_by": verdict["escalated_by"],
            "primary": {"class": p_vote[0], "confidence": p_vote[1], "ok": p_vote[2],
                        "http": p_meta.get("http"), "err": bool(p_meta.get("error"))},
            "secondary": {"class": s_vote[0], "confidence": s_vote[1], "ok": s_vote[2],
                          "http": s_meta.get("http"), "err": bool(s_meta.get("error"))},
        })

        if (i + 1) % 20 == 0 or (i + 1) == len(manifest):
            print(f"  [{i+1}/{len(manifest)}] cum spend ${spend.usd:.4f} "
                  f"(live {spend.calls}, cache {spend.cache_hits}, fail {spend.failures})",
                  flush=True)

        if spend.usd >= SPEND_SOFT_CEILING:
            print(f"HALT: spend ${spend.usd:.2f} crossed soft ceiling "
                  f"${SPEND_SOFT_CEILING}. Checkpointing partial results.", flush=True)
            break

    pos = tp + fn
    neg = tn + fp
    sensitivity = (tp / pos) if pos else None
    specificity = (tn / neg) if neg else None
    ppv = (tp / (tp + fp)) if (tp + fp) else None
    npv = (tn / (tn + fn)) if (tn + fn) else None

    result = {
        "run_config": {
            "primary_model": args.primary_model,
            "secondary_model": args.secondary_model,
            "confidence_floor": floor,
            "max_tokens": args.max_tokens,
            "dataset": "xuxuxuxuxu/SurgWound (SurgWound-Bench)",
            "split": "test",
            "field": "Urgency Level",
            "positive_class": "Yellow+Red (Clinic within 48h / Emergency)",
            "negative_class": "Green (Home Care)",
            "n_images": len(records),
            "gateway": gw.GATEWAY_BASE,
            "prompt_hash_primary": gw._prompt_hash(primary_prompt, USER_TEXT),
            "prompt_hash_secondary": gw._prompt_hash(secondary_prompt, USER_TEXT),
        },
        "confusion_matrix": {"tp": tp, "fp": fp, "tn": tn, "fn": fn,
                             "positives": pos, "negatives": neg},
        "metrics": {
            "sensitivity_recall_urgent": sensitivity,
            "specificity": specificity,
            "ppv_precision": ppv,
            "npv": npv,
            "target_sensitivity": 0.89,
            "gate_met": (sensitivity is not None and sensitivity >= 0.89),
        },
        "spend": spend.summary(),
        "records": records,
    }

    out = args.out or os.path.join(HERE, "eval_result.json")
    with open(out, "w") as f:
        json.dump(result, f, indent=2)

    print("\n=== RESULT ===")
    print(f"n={len(records)}  TP={tp} FP={fp} TN={tn} FN={fn}")
    if sensitivity is not None:
        print(f"Sensitivity (urgent recall) = {sensitivity:.4f}  (target 0.89)  "
              f"{'MET' if sensitivity >= 0.89 else 'NOT MET'}")
    if specificity is not None:
        print(f"Specificity = {specificity:.4f}")
    print(f"Cumulative spend = ${spend.usd:.4f}  (live {spend.calls}, "
          f"cache {spend.cache_hits}, fail {spend.failures})")
    print(f"Wrote {out}")
    return 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--primary-model", default="claude-opus-4-8")
    ap.add_argument("--secondary-model", default="gemini-3.5-flash")
    ap.add_argument("--confidence-floor", type=float, default=0.7,
                    help="needs-review below this confidence escalates to urgent")
    ap.add_argument("--max-tokens", type=int, default=400)
    ap.add_argument("--timeout", type=int, default=90)
    ap.add_argument("--limit", type=int, default=0, help="cap images (0=all)")
    ap.add_argument("--out", default="")
    args = ap.parse_args()
    return run(args)


if __name__ == "__main__":
    sys.exit(main())
