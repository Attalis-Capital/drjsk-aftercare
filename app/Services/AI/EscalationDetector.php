<?php

namespace App\Services\AI;

use App\Models\Visit;

class EscalationDetector
{
    // Surgeon-confirmed URGENT triggers for the plastic/reconstructive surgery
    // pilot are the authoritative source of truth in prompts/escalation-detector.md.
    // The executed keyword path below MUST cover all five; the prompt-vs-code
    // consistency is asserted by EscalationDetectorTest (mission #1708).
    //
    // Surgeon URGENT triggers and the keyword groups that cover them:
    //   1. breathing difficulty / chest pain (possible PE)
    //   2. sudden severe swelling or pressure at the surgical site (haematoma)
    //   3. fever above 38.5C
    //   4. uncontrolled bleeding
    //   5. wound opening or separation (dehiscence)
    private const CRITICAL_KEYWORDS = [
        // Trigger 1: breathing difficulty / chest pain (possible pulmonary embolism)
        'chest pain', 'chest pressure', 'chest tightness',
        'can\'t breathe', 'cannot breathe', 'difficulty breathing', 'shortness of breath',
        // Trigger 2: sudden severe swelling or pressure at the surgical site (haematoma)
        'severe swelling', 'sudden swelling', 'severe pressure', 'haematoma', 'hematoma',
        'swelling at the site', 'swelling at the surgical site', 'tight and swollen',
        // Trigger 3: fever above 38.5C is NOT a keyword — it is a numeric threshold parsed
        // by detectFever() / FEVER_THRESHOLD_C. A bare 'fever' substring is deliberately
        // excluded here because it over-triggers on negations ("no fever", "worried about
        // fever but my temperature is normal"). See detectFever() for the parsing contract.
        // Trigger 4: uncontrolled bleeding
        'severe bleeding', 'uncontrolled bleeding', 'won\'t stop bleeding', 'will not stop bleeding',
        'bleeding heavily', 'soaking through',
        // Trigger 5: wound opening or separation (dehiscence)
        'wound opening', 'wound has opened', 'wound is opening', 'wound separation',
        'wound has separated', 'wound splitting', 'stitches came apart', 'incision opened',
        'dehiscence',
        // Other inherited critical triggers (general emergency)
        'worst headache', 'sudden headache',
        'passed out', 'fainted', 'lost consciousness', 'blacked out',
        'suicidal', 'kill myself', 'end my life', 'self-harm', 'hurt myself',
        'throat swelling', 'can\'t swallow', 'face drooping', 'arm weakness',
        'vision loss', 'can\'t see', 'sudden blindness',
    ];

    // The single source of truth for the critical-severity patient-facing action
    // string (surgeon-confirmed practice number + 000). Both the keyword path and
    // the #1701 wound-triage urgent path reuse THIS constant so the copy never
    // diverges. Do not inline this string elsewhere.
    public const CRITICAL_RECOMMENDED_ACTION = 'This sounds like it could be urgent. Please call the practice on (02) 9369 2800 now; in an emergency call 000. Do not wait.';

    /**
     * Surgeon-confirmed URGENT fever threshold in degrees Celsius. Mirrors the
     * "Fever above 38.5C" bullet in prompts/escalation-detector.md (authoritative).
     * Comparison is inclusive (>=) per mission #1708 Task 1. Do not change this value
     * without a corresponding surgeon-confirmed change to the prompt — the structural
     * test in EscalationDetectorTest asserts the prompt and this constant agree.
     */
    public const FEVER_THRESHOLD_C = 38.5;

    /**
     * Plausible human body-temperature window (deg C) after normalisation. Numbers
     * outside this window are not treated as temperatures, so "101 stitches" or a
     * house number never reads as a fever.
     */
    private const PLAUSIBLE_C_MIN = 30.0;

    private const PLAUSIBLE_C_MAX = 45.0;

    public function __construct(
        private AnthropicClient $client,
        private PromptLoader $promptLoader,
        private AiTierManager $tierManager,
    ) {}

    /**
     * The patient-facing critical-severity response (practice number + 000).
     * Reused by the #1701 wound-triage urgent path (decision D5) so the urgent
     * copy is identical to the #1708 chat escalation copy.
     */
    public function criticalRecommendedAction(): string
    {
        return self::CRITICAL_RECOMMENDED_ACTION;
    }

    /**
     * Evaluate a patient message for urgency.
     *
     * First performs a fast keyword check for critical terms,
     * then uses AI for nuanced evaluation if no critical keywords found.
     *
     * @param  string  $message  The patient's message text
     * @param  Visit|null  $visit  Visit context for condition-aware evaluation
     * @return array{is_urgent: bool, severity: string, reason: string, recommended_action: string}
     */
    public function evaluate(string $message, ?Visit $visit = null): array
    {
        // Fast path: check for critical keywords
        $keywordResult = $this->checkCriticalKeywords($message);
        if ($keywordResult['is_urgent']) {
            return $keywordResult;
        }

        // Skip AI evaluation — keyword check is sufficient for safety.
        // AI evaluation adds 5-15s latency before first chat token streams.
        // The aiEvaluate() method is kept intact for future background-check use.
        return [
            'is_urgent' => false,
            'severity' => 'low',
            'reason' => 'No critical keywords detected',
            'trigger_phrases' => [],
            'recommended_action' => 'No action needed',
            'context_factors' => [],
        ];
    }

    private function checkCriticalKeywords(string $message): array
    {
        $lower = strtolower($message);

        foreach (self::CRITICAL_KEYWORDS as $keyword) {
            if (str_contains($lower, $keyword)) {
                return [
                    'is_urgent' => true,
                    'severity' => 'critical',
                    'reason' => "Message contains critical symptom: '{$keyword}'",
                    'trigger_phrases' => [$keyword],
                    'recommended_action' => self::CRITICAL_RECOMMENDED_ACTION,
                    'context_factors' => [],
                ];
            }
        }

        // Trigger 3: fever above the surgeon-confirmed threshold. Numeric, not a
        // keyword — parse a temperature and compare, so "no fever" never escalates.
        $fever = $this->detectFever($lower);
        if ($fever['is_fever']) {
            return [
                'is_urgent' => true,
                'severity' => 'critical',
                'reason' => "Message reports a fever of {$fever['temp_c']}C (>= ".self::FEVER_THRESHOLD_C.'C)',
                'trigger_phrases' => [$fever['raw']],
                'recommended_action' => self::CRITICAL_RECOMMENDED_ACTION,
                'context_factors' => [],
            ];
        }

        return [
            'is_urgent' => false,
            'severity' => 'low',
            'reason' => 'No critical keywords detected',
            'trigger_phrases' => [],
            'recommended_action' => 'No action needed',
            'context_factors' => [],
        ];
    }

    /**
     * Detect a fever at or above FEVER_THRESHOLD_C from free-text patient input.
     *
     * Temperature-parsing contract (mission #1708 Task 1 / Council P1):
     *  - A number only counts as a temperature when anchored to a temperature
     *    indicator: an explicit unit (C, F, Celsius, Fahrenheit, degrees, or the °
     *    symbol) OR a nearby temperature word (temperature / temp / fever). A bare
     *    number elsewhere in the sentence ("39 years old", "no fever") is ignored —
     *    this is why 'fever' is deliberately NOT a keyword.
     *  - Unit assumption: an explicit F/Fahrenheit is converted from Fahrenheit;
     *    every other case is read as Celsius when the value is a plausible Celsius
     *    body temperature. An unqualified value that is only plausible as Fahrenheit
     *    (e.g. "temperature of 101") is converted from Fahrenheit — a fail-safe that
     *    leans toward detecting a fever rather than missing one.
     *  - Range validation: only values normalising to PLAUSIBLE_C_MIN..MAX are
     *    accepted, so non-temperature numbers cannot read as a fever.
     *  - Comparison is inclusive: >= FEVER_THRESHOLD_C is a fever (38.4999 is not).
     *
     * @return array{is_fever: bool, temp_c: float|null, raw: string|null}
     */
    private function detectFever(string $lower): array
    {
        $candidates = [];

        // Unit-anchored: "38.5c", "38.5 °c", "101.5 fahrenheit", "39 degrees".
        if (preg_match_all('/(\d{2,3}(?:\.\d+)?)\s*°?\s*(celsius|fahrenheit|degrees?|c|f)\b/u', $lower, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $hit) {
                $candidates[] = [(float) $hit[1], $hit[2], trim($hit[0])];
            }
        }

        // Temperature-word-anchored: "temperature of 39", "temp was 39.2", "fever of 40".
        if (preg_match_all('/(?:temperature|temp|fever)\b[^0-9]{0,20}?(\d{2,3}(?:\.\d+)?)\s*°?\s*(celsius|fahrenheit|degrees?|c|f)?\b/u', $lower, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $hit) {
                $candidates[] = [(float) $hit[1], $hit[2] ?? '', trim($hit[0])];
            }
        }

        $best = null;
        $bestRaw = null;
        foreach ($candidates as [$value, $unit, $raw]) {
            $celsius = $this->normaliseToCelsius($value, $unit);
            if ($celsius === null) {
                continue;
            }
            if ($best === null || $celsius > $best) {
                $best = $celsius;
                $bestRaw = $raw;
            }
        }

        if ($best === null) {
            return ['is_fever' => false, 'temp_c' => null, 'raw' => null];
        }

        return [
            'is_fever' => $best >= self::FEVER_THRESHOLD_C,
            'temp_c' => round($best, 1),
            'raw' => $bestRaw,
        ];
    }

    /**
     * Normalise a parsed (value, unit) pair to degrees Celsius, or null if the
     * value cannot be a plausible human body temperature. See detectFever() for
     * the full contract.
     */
    private function normaliseToCelsius(float $value, string $unit): ?float
    {
        $unit = strtolower(trim($unit));

        if ($unit === 'f' || $unit === 'fahrenheit') {
            $celsius = ($value - 32) * 5 / 9;
        } elseif ($value >= self::PLAUSIBLE_C_MIN && $value <= self::PLAUSIBLE_C_MAX) {
            $celsius = $value;
        } elseif ($value >= 90.0 && $value <= 113.0) {
            // Only plausible as Fahrenheit — convert (fail-safe toward detection).
            $celsius = ($value - 32) * 5 / 9;
        } else {
            return null;
        }

        if ($celsius < self::PLAUSIBLE_C_MIN || $celsius > self::PLAUSIBLE_C_MAX) {
            return null;
        }

        return $celsius;
    }

    private function aiEvaluate(string $message, ?Visit $visit): array
    {
        $tier = $this->tierManager->current();
        $systemPrompt = $this->promptLoader->load('escalation-detector');

        $input = "Evaluate the following patient message for urgency.\n\n";
        $input .= "Patient Message: {$message}\n\n";

        if ($visit) {
            $conditions = [];
            if ($visit->conditions) {
                foreach ($visit->conditions as $condition) {
                    $conditions[] = $condition->display_name;
                }
            }

            if ($conditions) {
                $input .= 'Known Conditions: '.implode(', ', $conditions)."\n";
            }

            $input .= 'Visit Specialty: '.($visit->specialty ?? 'general')."\n";
        }

        $messages = [
            ['role' => 'user', 'content' => $input],
        ];

        // Opus 4.6 tier: use extended thinking for clinical reasoning before escalation decision
        if ($tier->escalationThinkingEnabled()) {
            $result = $this->client->chatWithThinking($systemPrompt, $messages, [
                'model' => $tier->model(),
                'max_tokens' => 8000,
                'budget_tokens' => $tier->thinkingBudget('escalation'),
            ]);

            $parsed = $this->parseJsonResponse($result['text']);
            $parsed['clinical_reasoning'] = $result['thinking'];

            return $parsed;
        }

        $response = $this->client->chat($systemPrompt, $messages, [
            'model' => $tier->model(),
            'max_tokens' => 512,
        ]);

        return $this->parseJsonResponse($response);
    }

    private function parseJsonResponse(string $response): array
    {
        return AnthropicClient::parseJsonOutput($response, [
            'is_urgent' => false,
            'severity' => 'low',
            'reason' => 'Unable to evaluate (parse error)',
            'trigger_phrases' => [],
            'recommended_action' => 'No action needed',
            'context_factors' => [],
        ]);
    }
}
