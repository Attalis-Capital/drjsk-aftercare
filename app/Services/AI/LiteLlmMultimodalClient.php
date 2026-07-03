<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * LiteLLM-gateway-only multimodal client for wound-photo triage (mission #1701).
 *
 * Both ensemble voters (primary and secondary) route through the LiteLLM gateway
 * (config('triage.gateway.base_url')) via the OpenAI-compatible
 * /v1/chat/completions endpoint. There are NO direct provider endpoints in this
 * path - no api.anthropic.com, no generativelanguage.googleapis.com, no
 * api.openai.com. This is the wiring counterpart of eval/gateway_client.py.
 *
 * The existing AnthropicClient talks to api.anthropic.com directly and has no
 * Gemini/OpenAI or gateway support, so it is deliberately NOT reused here.
 *
 * Safety contract (D2 fail-toward-escalation): classify() NEVER throws. On any
 * transport error, HTTP error, or unparseable/off-schema output it returns a
 * result with ok=false so the ensemble treats it as an escalation signal.
 *
 * Privacy: the gateway virtual key is read from config at call time only and is
 * never logged. No image bytes and no free-text rationale are ever logged.
 */
class LiteLlmMultimodalClient
{
    /**
     * Classify a single wound image with one voter model.
     *
     * @param  string  $model  Gateway model alias (e.g. claude-opus-4-8).
     * @param  string  $systemPrompt  The voter system prompt (from PromptLoader).
     * @param  string  $userText  Instruction text sent alongside the image.
     * @param  string  $imageBase64  Base64-encoded image bytes (no data: prefix).
     * @param  string  $mimeType  Image MIME type (e.g. image/jpeg).
     * @param  array  $options  max_tokens, temperature, timeout, retries, extra_params.
     * @return array{class: ?string, confidence: float, rationale: ?string, features: array, ok: bool, http: ?int, error: ?string}
     */
    public function classify(
        string $model,
        string $systemPrompt,
        string $userText,
        string $imageBase64,
        string $mimeType,
        array $options = [],
    ): array {
        $baseUrl = rtrim((string) config('triage.gateway.base_url'), '/');
        $apiKey = (string) config('triage.gateway.api_key');
        $maxTokens = $options['max_tokens'] ?? (int) config('triage.max_tokens', 400);
        $temperature = $options['temperature'] ?? (float) config('triage.temperature', 0);
        $timeout = $options['timeout'] ?? (int) config('triage.request_timeout', 90);
        $retries = $options['retries'] ?? (int) config('triage.retries', 2);
        $extraParams = $options['extra_params'] ?? [];

        $dataUrl = 'data:'.$mimeType.';base64,'.$imageBase64;

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => $userText],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ]],
            ],
        ];
        if (! empty($extraParams)) {
            $payload = array_merge($payload, $extraParams);
        }

        $body = json_encode($payload);
        $endpoint = $baseUrl.'/v1/chat/completions';

        $lastError = null;
        $httpCode = null;

        $maxAttempts = $retries + 1;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            [$rawResponse, $httpCode, $transportError] = $this->post($endpoint, $apiKey, $body, $timeout);

            if ($transportError !== null) {
                $lastError = $transportError;
                $this->sleepBackoff($attempt);

                continue;
            }

            // 4xx will not improve on retry (bad image, bad request) - stop.
            if ($httpCode !== null && $httpCode >= 400 && $httpCode < 500) {
                $lastError = "HTTP {$httpCode}";
                break;
            }

            if ($httpCode !== null && $httpCode >= 500) {
                $lastError = "HTTP {$httpCode}";
                $this->sleepBackoff($attempt);

                continue;
            }

            // 2xx: parse.
            $content = $this->extractContent($rawResponse);
            $parsed = $this->parseJsonBlock($content);
            $normalised = $this->normalise($parsed);

            if ($normalised === null) {
                // Off-schema / unusable output -> escalation signal.
                Log::warning('Wound triage voter returned unusable output', [
                    'model' => $model,
                    'http' => $httpCode,
                    // NOTE: no image bytes, no rationale, no patient identifiers.
                ]);

                return $this->failResult($httpCode, 'unparseable_or_off_schema');
            }

            return [
                'class' => $normalised['class'],
                'confidence' => $normalised['confidence'],
                'rationale' => $normalised['rationale'],
                'features' => $normalised['features'],
                'ok' => true,
                'http' => $httpCode,
                'error' => null,
            ];
        }

        Log::warning('Wound triage voter call failed', [
            'model' => $model,
            'http' => $httpCode,
            'error' => $lastError,
        ]);

        return $this->failResult($httpCode, $lastError ?? 'transport_failure');
    }

    /**
     * Perform the POST via cURL. Returns [decodedBodyOrNull, httpCode, transportErrorOrNull].
     *
     * @return array{0: ?array, 1: ?int, 2: ?string}
     */
    protected function post(string $endpoint, string $apiKey, string $body, int $timeout): array
    {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                // Browser-like UA: the gateway edge (Cloudflare) 1010-challenges
                // the default agent. Mirrors eval/gateway_client.py.
                'User-Agent: curl/8.5.0',
            ],
        ]);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
        $curlErr = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        if ($curlErr !== null) {
            return [null, $httpCode, $curlErr];
        }

        $decoded = json_decode((string) $raw, true);
        if (! is_array($decoded)) {
            // A 2xx with non-JSON body is a usable-output failure at parse time,
            // but a non-2xx with non-JSON body is a transport-level failure.
            if ($httpCode !== null && $httpCode >= 200 && $httpCode < 300) {
                return [[], $httpCode, null];
            }

            return [null, $httpCode, 'non_json_response'];
        }

        return [$decoded, $httpCode, null];
    }

    protected function sleepBackoff(int $attempt): void
    {
        // Small linear backoff, mirrors the eval client. Skipped under test where
        // the transport is stubbed.
        if (app()->runningUnitTests()) {
            return;
        }
        usleep((int) (1_500_000 * $attempt));
    }

    /**
     * Pull the assistant message text from an OpenAI-compatible response body.
     */
    protected function extractContent(?array $body): string
    {
        if (! is_array($body)) {
            return '';
        }
        $content = $body['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            // Some gateways return content as an array of blocks.
            $text = '';
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text') {
                    $text .= $block['text'] ?? '';
                }
            }

            return $text;
        }

        return (string) $content;
    }

    /**
     * Extract the first balanced JSON object from a model response.
     * Mirrors eval/gateway_client.py::_parse_json_block.
     */
    public function parseJsonBlock(string $text): ?array
    {
        $s = trim($text);
        if ($s === '') {
            return null;
        }
        if (str_starts_with($s, '```')) {
            $parts = explode('```', $s);
            if (count($parts) > 1) {
                $s = $parts[1];
                if (str_starts_with(strtolower(ltrim($s)), 'json')) {
                    $s = substr(ltrim($s), 4);
                }
            }
        }
        $start = strpos($s, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $len = strlen($s);
        for ($i = $start; $i < $len; $i++) {
            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $frag = substr($s, $start, $i - $start + 1);
                    $decoded = json_decode($frag, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * Turn a parsed model dict into a normalised vote, or null if unusable.
     * Mirrors eval/ensemble.py::normalise_vote's parse half.
     *
     * @return array{class: string, confidence: float, rationale: ?string, features: array}|null
     */
    protected function normalise(?array $parsed): ?array
    {
        if (! is_array($parsed)) {
            return null;
        }
        $cls = strtolower(trim((string) ($parsed['class'] ?? '')));
        if (! in_array($cls, TriageVerdict::VALID_CLASSES, true)) {
            // Any off-schema class (including any discharge/normal) is unusable.
            return null;
        }
        $conf = $parsed['confidence'] ?? 0.0;
        if (! is_numeric($conf)) {
            $conf = 0.0;
        }
        $conf = max(0.0, min(1.0, (float) $conf));

        $features = $parsed['features'] ?? [];
        if (! is_array($features)) {
            $features = [];
        }

        return [
            'class' => $cls,
            'confidence' => $conf,
            'rationale' => isset($parsed['rationale']) ? (string) $parsed['rationale'] : null,
            'features' => array_values(array_map('strval', $features)),
        ];
    }

    /**
     * @return array{class: null, confidence: float, rationale: null, features: array, ok: false, http: ?int, error: string}
     */
    protected function failResult(?int $httpCode, string $error): array
    {
        return [
            'class' => null,
            'confidence' => 0.0,
            'rationale' => null,
            'features' => [],
            'ok' => false,
            'http' => $httpCode,
            'error' => $error,
        ];
    }
}
