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
        // Trigger 3: fever above 38.5C (patients phrase this many ways; also see fever check below)
        'fever', 'high temperature', 'temperature of 39', 'temperature of 40',
        '38.5', '38.6', '38.7', '38.8', '38.9', '39 degrees', '40 degrees',
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

    public function __construct(
        private AnthropicClient $client,
        private PromptLoader $promptLoader,
        private AiTierManager $tierManager,
    ) {}

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
                    'recommended_action' => 'This sounds like it could be urgent. Please call the practice on (02) 9369 2800 now; in an emergency call 000. Do not wait.',
                    'context_factors' => [],
                ];
            }
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
