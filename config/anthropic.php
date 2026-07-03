<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Anthropic API Key
    |--------------------------------------------------------------------------
    |
    | Your Anthropic API key. This is used by the anthropic-ai/laravel SDK.
    |
    */
    'api_key' => env('ANTHROPIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Base URL
    |--------------------------------------------------------------------------
    |
    | The Anthropic-compatible endpoint the chat/QA path calls. Defaults to the
    | public Anthropic API, but on the DrJSK staging/pilot runtime the only model
    | credential is the LiteLLM VK, which authenticates against the gateway (not
    | api.anthropic.com direct). Set ANTHROPIC_BASE_URL to the gateway there. The
    | gateway accepts native Anthropic /v1/messages with the VK (verified HTTP
    | 200, x-api-key and Bearer), so only the base URL changes -- the request
    | format is unchanged. (#1731 / #1723 staging chat fix.)
    |
    */
    'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),

    /*
    |--------------------------------------------------------------------------
    | Default Model
    |--------------------------------------------------------------------------
    |
    | The default Claude model used for AI requests when no tier override.
    | Production/demo: claude-opus-4-8 (claude-opus-4-6 does not exist on the
    | gateway).
    | Tests/development: claude-sonnet-4-5-20250929 (cost optimization)
    |
    */
    'default_model' => env('ANTHROPIC_MODEL', 'claude-opus-4-8'),

    /*
    |--------------------------------------------------------------------------
    | AI Tier
    |--------------------------------------------------------------------------
    |
    | Model and feature selection is controlled by the AiTier system:
    |   - good: Sonnet, no thinking, no caching, no guidelines
    |   - better: Opus, thinking on chat/scribe, caching, no guidelines
    |   - opus46: Opus, full thinking (including escalation), caching, guidelines
    |
    | The tier is set via API (PUT /api/v1/settings/ai-tier) and stored in cache.
    | Default: opus46 (full Opus 4.6 experience)
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Prompt Caching
    |--------------------------------------------------------------------------
    |
    | Cache control for system prompts and guidelines.
    | Reduces input token costs by ~90% on repeated requests.
    | Enabled/disabled per tier — this config only sets the TTL.
    |
    */
    'cache' => [
        'ttl' => env('ANTHROPIC_CACHE_TTL', '5m'),
    ],

];
