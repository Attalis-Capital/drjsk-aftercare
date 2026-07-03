#!/usr/bin/env python3
"""LiteLLM-gateway-only multimodal client for the wound-triage eval harness.

All model calls route through the LiteLLM gateway (litellm.attaliscapital.com)
via the OpenAI-compatible /v1/chat/completions endpoint. No direct provider
SDKs or endpoints. No secrets are read or logged - the gateway virtual key is
sourced from the environment (ANTHROPIC_API_KEY) at call time only.

Cost discipline (mission #1701, HARD $200/day ceiling):
  - Every response is cached on disk keyed by (image_id, model, prompt_hash).
    Re-runs during prompt/threshold iteration cost nothing.
  - Token usage is converted to an estimated USD spend and accumulated. A
    running cumulative total is exposed for the runner to print and gate on.

This file is committed as harness code. It does NOT commit any dataset image.
"""
import base64
import hashlib
import json
import os
import time
import urllib.request
import urllib.error

GATEWAY_BASE = os.environ.get("ANTHROPIC_BASE_URL", "https://litellm.attaliscapital.com").rstrip("/")
GATEWAY_KEY = os.environ.get("ANTHROPIC_API_KEY", "")
CACHE_DIR = os.path.join(os.path.dirname(__file__), ".cache", "responses")
os.makedirs(CACHE_DIR, exist_ok=True)

# Estimated USD per 1M tokens (input, output). These are conservative public
# list-price estimates used only to track spend against the $200/day ceiling;
# the gateway is the source of truth for actual billing. Documented in the
# report so the spend figure is transparent and reproducible.
PRICE_PER_MTOK = {
    "claude-opus-4-8": (15.0, 75.0),
    "gemini-3.5-flash": (0.30, 2.50),
}
# Fallback price if a model is not in the table (use the most expensive known).
_FALLBACK_PRICE = (15.0, 75.0)


class SpendTracker:
    def __init__(self):
        self.input_tokens = 0
        self.output_tokens = 0
        self.usd = 0.0
        self.calls = 0
        self.cache_hits = 0
        self.failures = 0
        self.per_model = {}

    def add(self, model, in_tok, out_tok):
        pin, pout = PRICE_PER_MTOK.get(model, _FALLBACK_PRICE)
        cost = (in_tok / 1_000_000.0) * pin + (out_tok / 1_000_000.0) * pout
        self.input_tokens += in_tok
        self.output_tokens += out_tok
        self.usd += cost
        self.calls += 1
        pm = self.per_model.setdefault(model, {"in": 0, "out": 0, "usd": 0.0, "calls": 0})
        pm["in"] += in_tok
        pm["out"] += out_tok
        pm["usd"] += cost
        pm["calls"] += 1
        return cost

    def summary(self):
        return {
            "cumulative_usd": round(self.usd, 4),
            "live_calls": self.calls,
            "cache_hits": self.cache_hits,
            "failures": self.failures,
            "input_tokens": self.input_tokens,
            "output_tokens": self.output_tokens,
            "per_model": {
                k: {"calls": v["calls"], "in": v["in"], "out": v["out"], "usd": round(v["usd"], 4)}
                for k, v in self.per_model.items()
            },
        }


def _prompt_hash(system_prompt, user_text):
    h = hashlib.sha256()
    h.update(system_prompt.encode("utf-8"))
    h.update(b"\x00")
    h.update(user_text.encode("utf-8"))
    return h.hexdigest()[:16]


def _cache_key(image_id, model, prompt_hash):
    raw = f"{image_id}|{model}|{prompt_hash}"
    return hashlib.sha256(raw.encode("utf-8")).hexdigest()[:24]


def _cache_path(key):
    return os.path.join(CACHE_DIR, key + ".json")


def _image_data_url(image_path):
    with open(image_path, "rb") as f:
        raw = f.read()
    b64 = base64.b64encode(raw).decode("ascii")
    # SurgWound images are JPEG.
    return f"data:image/jpeg;base64,{b64}"


def classify(image_id, image_path, model, system_prompt, user_text,
             spend, max_tokens=400, timeout=90, retries=2, extra_params=None):
    """Return (parsed_dict_or_None, meta). Cached by (image_id, model, prompt_hash).

    meta = {"cached": bool, "http": int|None, "error": str|None, "raw": str}
    On any transport/parse failure returns (None, meta) so the ensemble can apply
    the fail-toward-escalation rule (a failed call -> urgent).
    """
    phash = _prompt_hash(system_prompt, user_text)
    if extra_params:
        # config that changes the request must bust the cache
        phash = hashlib.sha256(
            (phash + json.dumps(extra_params, sort_keys=True)).encode("utf-8")
        ).hexdigest()[:16]
    key = _cache_key(image_id, model, phash)
    cpath = _cache_path(key)
    if os.path.exists(cpath):
        with open(cpath) as f:
            entry = json.load(f)
        spend.cache_hits += 1
        return entry.get("parsed"), {"cached": True, "http": entry.get("http"),
                                     "error": entry.get("error"), "raw": entry.get("raw", "")}

    payload = {
        "model": model,
        "max_tokens": max_tokens,
        "temperature": 0,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": [
                {"type": "text", "text": user_text},
                {"type": "image_url", "image_url": {"url": _image_data_url(image_path)}},
            ]},
        ],
    }
    if extra_params:
        payload.update(extra_params)
    data = json.dumps(payload).encode("utf-8")
    last_err = None
    http_code = None
    for attempt in range(retries + 1):
        req = urllib.request.Request(
            f"{GATEWAY_BASE}/v1/chat/completions", data=data,
            headers={
                "Authorization": f"Bearer {GATEWAY_KEY}",
                "content-type": "application/json",
                # A browser-like UA avoids the gateway edge (Cloudflare) blocking
                # the default urllib agent with a 1010 challenge.
                "User-Agent": "curl/8.5.0",
                "Accept": "application/json",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                http_code = r.getcode()
                body = json.load(r)
            content = body["choices"][0]["message"].get("content") or ""
            usage = body.get("usage", {}) or {}
            in_tok = usage.get("prompt_tokens", 0) or 0
            out_tok = usage.get("completion_tokens", 0) or 0
            spend.add(model, in_tok, out_tok)
            parsed = _parse_json_block(content)
            entry = {"parsed": parsed, "http": http_code, "error": None,
                     "raw": content, "usage": {"in": in_tok, "out": out_tok}}
            with open(cpath, "w") as f:
                json.dump(entry, f)
            return parsed, {"cached": False, "http": http_code, "error": None, "raw": content}
        except urllib.error.HTTPError as e:
            http_code = e.code
            try:
                last_err = e.read().decode("utf-8", "replace")[:300]
            except Exception:
                last_err = f"HTTP {e.code}"
            # 4xx (bad image etc) will not improve on retry; break.
            if 400 <= e.code < 500:
                break
        except Exception as e:
            last_err = str(e)[:300]
        time.sleep(1.5 * (attempt + 1))
    spend.failures += 1
    entry = {"parsed": None, "http": http_code, "error": last_err, "raw": ""}
    with open(cpath, "w") as f:
        json.dump(entry, f)
    return None, {"cached": False, "http": http_code, "error": last_err, "raw": ""}


def _parse_json_block(text):
    """Extract the first JSON object from a model response robustly."""
    if not text:
        return None
    s = text.strip()
    if s.startswith("```"):
        # strip code fence
        s = s.split("```", 2)
        s = s[1] if len(s) > 1 else text
        if s.lstrip().lower().startswith("json"):
            s = s.lstrip()[4:]
    # find first { ... } balanced
    start = s.find("{")
    if start == -1:
        return None
    depth = 0
    for i in range(start, len(s)):
        if s[i] == "{":
            depth += 1
        elif s[i] == "}":
            depth -= 1
            if depth == 0:
                frag = s[start:i + 1]
                try:
                    return json.loads(frag)
                except Exception:
                    return None
    return None
