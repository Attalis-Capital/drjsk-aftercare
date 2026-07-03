#!/usr/bin/env python3
"""Fetch SurgWound-Bench test split Urgency Level rows via the HF datasets-server.

Downloads only the Urgency Level VQA rows (one per image), decodes the embedded
base64 image, and writes a manifest plus image cache under eval/.cache/ (gitignored).
No dataset images are committed. CC-BY-SA-4.0 - attribution recorded in eval/README.
"""
import base64, json, os, sys, time, urllib.request

DATASET = "xuxuxuxuxu/SurgWound"
CONFIG = "default"
SPLIT = "test"
BASE = "https://datasets-server.huggingface.co/rows"
CACHE = os.path.join(os.path.dirname(__file__), ".cache")
IMG_DIR = os.path.join(CACHE, "images")
os.makedirs(IMG_DIR, exist_ok=True)

def fetch(offset, length):
    url = f"{BASE}?dataset={DATASET}&config={CONFIG}&split={SPLIT}&offset={offset}&length={length}"
    for attempt in range(5):
        try:
            with urllib.request.urlopen(url, timeout=60) as r:
                return json.load(r)
        except Exception as e:
            time.sleep(2 * (attempt + 1))
            if attempt == 4:
                raise
    return None

def main():
    first = fetch(0, 1)
    total = first.get("num_rows_total")
    print(f"total rows in {SPLIT}: {total}", flush=True)
    manifest = []
    offset = 0
    STEP = 100
    while offset < total:
        d = fetch(offset, STEP)
        rows = d.get("rows", [])
        if not rows:
            break
        for rw in rows:
            r = rw["row"]
            if r.get("field") != "Urgency Level":
                continue
            image_name = r.get("image_name")
            b64 = r.get("image", "")
            try:
                raw = base64.b64decode(b64)
            except Exception:
                print("  decode fail", image_name); continue
            ext = ".jpg"
            fname = f"{image_name}"
            fpath = os.path.join(IMG_DIR, fname)
            with open(fpath, "wb") as f:
                f.write(raw)
            manifest.append({
                "id": r.get("id"),
                "image_name": image_name,
                "answer": r.get("answer"),
                "options": r.get("options"),
                "image_path": os.path.relpath(fpath, os.path.dirname(__file__)),
                "bytes": len(raw),
            })
        offset += STEP
        print(f"  processed offset {offset}/{total}, urgency rows so far {len(manifest)}", flush=True)
    mpath = os.path.join(CACHE, "urgency_manifest.json")
    with open(mpath, "w") as f:
        json.dump(manifest, f, indent=2)
    print(f"wrote {len(manifest)} urgency-level rows to {mpath}", flush=True)
    # label distribution
    from collections import Counter
    c = Counter(m["answer"] for m in manifest)
    print("label distribution:")
    for k, v in c.items():
        print(f"  {k}: {v}")

if __name__ == "__main__":
    main()
