<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wound-photo triage operating point (mission #1701)
    |--------------------------------------------------------------------------
    |
    | Every value that defines the accepted operating point lives here so that a
    | James-tuned v4 operating point is a CONFIG CHANGE plus a ~$5 eval re-run,
    | with zero code rework. The defaults below are the accepted v3 pilot point
    | and MUST match the committed eval run_config (eval/REPORT.md):
    |
    |   - primary voter  : claude-opus-4-8
    |   - secondary voter: gemini-3.5-flash
    |   - confidence floor: 0.7 (stable 0.5-0.8 plateau; see the floor sweep)
    |   - temperature 0, max_tokens 400
    |
    | Changing a voter, the floor, or the thresholds here changes the live
    | operating point; re-run eval/run_eval.py against the same config to
    | re-measure sensitivity/specificity before shipping the change.
    |
    */

    // Master switch for the wound-photo triage feature (pilot).
    'enabled' => env('TRIAGE_ENABLED', true),

    /*
    | Ensemble voters. D1: primary claude-opus-4-8 + second voter
    | gemini-3.5-flash, both routed through the LiteLLM gateway. gpt-5.4 is a
    | CONFIGURABLE fallback voter (a config value, never hardcoded in a branch).
    | To swap the second voter to the fallback for a v4 experiment, set
    | TRIAGE_SECONDARY_MODEL=gpt-5.4 (or edit 'secondary' below) and re-run eval.
    */
    'voters' => [
        // Model aliases as registered on the LiteLLM gateway. These are the
        // eval run_config aliases; keep them identical so eval and app agree.
        'primary' => env('TRIAGE_PRIMARY_MODEL', 'claude-opus-4-8'),
        'secondary' => env('TRIAGE_SECONDARY_MODEL', 'gemini-3.5-flash'),

        // Configurable fallback voter (D1). Not wired into the default path;
        // present so a v4 voter swap is a config edit, not a code change.
        'fallback' => env('TRIAGE_FALLBACK_MODEL', 'gpt-5.4'),
    ],

    /*
    | Per-voter request parameters. The secondary voter is called with
    | reasoning_effort: none in the eval (prevents its internal reasoning tokens
    | truncating the JSON and reduces cost). extra_params is merged into the
    | gateway request verbatim, so a v4 tweak is a config edit.
    */
    'voter_params' => [
        'primary' => [
            'extra_params' => [],
        ],
        'secondary' => [
            'extra_params' => ['reasoning_effort' => 'none'],
        ],
    ],

    /*
    | D2 OR-gate confidence floor. A needs-review vote below this floor forces
    | urgent. 0.7 is the accepted v3 operating point (stable plateau). This value
    | MUST match eval/REPORT.md so app behaviour and the eval agree.
    */
    'confidence_floor' => (float) env('TRIAGE_CONFIDENCE_FLOOR', 0.7),

    // Shared generation params (match the eval run_config).
    'max_tokens' => (int) env('TRIAGE_MAX_TOKENS', 400),
    'temperature' => (float) env('TRIAGE_TEMPERATURE', 0),

    // Per-call transport budget for the multimodal client.
    'request_timeout' => (int) env('TRIAGE_REQUEST_TIMEOUT', 90),
    'retries' => (int) env('TRIAGE_RETRIES', 2),

    /*
    | Prompt names resolved by the app PromptLoader against prompts/*.md. These
    | are the SINGLE SOURCE OF TRUTH prompts committed by the eval; the app
    | reuses the exact files, it does not fork a second copy.
    */
    'prompts' => [
        'primary' => env('TRIAGE_PRIMARY_PROMPT', 'wound-triage-primary'),
        'secondary' => env('TRIAGE_SECONDARY_PROMPT', 'wound-triage-secondary'),
    ],

    // Document types that route to wound triage (patient-initiated wound photos).
    'triage_document_type' => 'wound_photo',

    /*
    |--------------------------------------------------------------------------
    | LiteLLM gateway (the ONLY model transport for triage)
    |--------------------------------------------------------------------------
    |
    | Both voters route through the LiteLLM gateway OpenAI-compatible endpoint.
    | No direct provider endpoints in the triage path. The virtual key is the
    | gateway credential; it is read from the environment at call time only and
    | is never logged. This mirrors eval/gateway_client.py.
    |
    */
    'gateway' => [
        'base_url' => env('LITELLM_BASE_URL', env('ANTHROPIC_BASE_URL', 'https://litellm.attaliscapital.com')),
        // Virtual key lives only in the gateway env; reuse the same var the eval
        // and AnthropicClient already source. Never logged.
        'api_key' => env('LITELLM_API_KEY', env('ANTHROPIC_API_KEY')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Langfuse tracing (self-hosted Railway ONLY)
    |--------------------------------------------------------------------------
    |
    | Tracing points at the self-hosted Railway Langfuse instance ONLY.
    | cloud.langfuse.com is PROHIBITED (mission #1393). Only non-PHI metadata is
    | traced (see TriageService::traceFields()); no image bytes, no patient
    | identifiers, no rationale free-text that could carry PHI.
    |
    */
    'langfuse' => [
        'enabled' => env('LANGFUSE_ENABLED', false),
        'host' => env('LANGFUSE_HOST'), // self-hosted Railway URL only
        'public_key' => env('LANGFUSE_PUBLIC_KEY'),
        // Secret key is read from env at call time only; never logged.
        'secret_key' => env('LANGFUSE_SECRET_KEY'),
    ],

];
