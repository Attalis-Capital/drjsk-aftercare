<?php

namespace Tests\Unit;

use App\Services\AI\AiTierManager;
use App\Services\AI\AnthropicClient;
use App\Services\AI\EscalationDetector;
use App\Services\AI\PromptLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Clinical-safety tests (mission #1708).
 *
 * The surgeon-confirmed URGENT triggers in prompts/escalation-detector.md are the
 * authoritative source of truth. The executed triage path (evaluate() ->
 * checkCriticalKeywords()) must fire is_urgent:true / severity:critical for all
 * five. These tests assert each trigger fires AND that the prompt and the runtime
 * list never diverge again (the exact gap the #2 review found: the PR body verified
 * the PROMPT while the executed path ignored three of the five triggers).
 *
 * Fever is a NUMERIC threshold (>= FEVER_THRESHOLD_C), not a keyword — see the
 * temperature-parsing cases and Council P1 contract below.
 */
class EscalationDetectorTest extends TestCase
{
    private function detector(): EscalationDetector
    {
        // The keyword/fever fast-path does not call the AI client, so real
        // collaborators are fine; evaluate() returns before aiEvaluate().
        return new EscalationDetector(
            app(AnthropicClient::class),
            app(PromptLoader::class),
            app(AiTierManager::class),
        );
    }

    /**
     * One representative patient phrasing per surgeon-confirmed URGENT trigger.
     * Each MUST classify is_urgent:true / severity:critical.
     *
     * @return array<string, array{0: string}>
     */
    public static function urgentTriggerMessages(): array
    {
        return [
            'breathing difficulty / chest pain (PE)' => ['I have sudden chest pain and shortness of breath.'],
            'haematoma - sudden severe swelling at site' => ['There is sudden severe swelling at the surgical site and it feels tight and swollen.'],
            'fever above 38.5C' => ['My temperature is 39 and it is not coming down.'],
            'uncontrolled bleeding' => ['The wound is bleeding heavily and it won\'t stop bleeding.'],
            'wound dehiscence - opening/separation' => ['My wound has opened and the stitches came apart.'],
        ];
    }

    #[DataProvider('urgentTriggerMessages')]
    public function test_each_surgeon_urgent_trigger_fires_critical(string $message): void
    {
        $result = $this->detector()->evaluate($message);

        $this->assertTrue($result['is_urgent'], "Expected is_urgent:true for message: {$message}");
        $this->assertSame('critical', $result['severity'], "Expected severity:critical for message: {$message}");
        $this->assertStringContainsString('9369 2800', $result['recommended_action']);
        $this->assertStringContainsString('000', $result['recommended_action']);
    }

    /**
     * Temperature-parsing contract (Council P1). At least four input variants
     * including a Fahrenheit case and the 38.4999 boundary, plus negation/age
     * false-positive guards. Fever is inclusive at FEVER_THRESHOLD_C (38.5C).
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function feverTemperatureCases(): array
    {
        return [
            // Celsius, explicit unit, at the inclusive threshold.
            'exactly 38.5C fires' => ['My temperature is 38.5°C.', true],
            'spelled-out Celsius fires' => ['The thermometer reads 38.5 Celsius.', true],
            'degrees suffix fires' => ['I have 39 degrees right now.', true],
            // Celsius, temperature-word anchored, no unit.
            'bare number via temp word fires' => ['My temperature is 39.', true],
            // Boundary: just below threshold must NOT fire.
            'boundary 38.4999 does not fire' => ['My temperature is 38.4999.', false],
            'normal 36.8C does not fire' => ['My temperature is 36.8C.', false],
            // Fahrenheit conversion (101.5F = 38.6C fires; 100F = 37.8C does not).
            'fahrenheit 101.5F fires' => ['My temperature is 101.5F.', true],
            'fahrenheit 100F does not fire' => ['My temperature is 100F.', false],
            // Negation / non-temperature-number false-positive guards.
            'no fever with normal reading does not fire' => ['I have no fever, my temperature is 36.5C.', false],
            'age is not read as a temperature' => ['I am 39 years old, recovering well, no fever.', false],
        ];
    }

    #[DataProvider('feverTemperatureCases')]
    public function test_fever_temperature_parsing(string $message, bool $expectUrgent): void
    {
        $result = $this->detector()->evaluate($message);

        $this->assertSame(
            $expectUrgent,
            $result['is_urgent'],
            "Fever parse mismatch for message: {$message}"
        );

        if ($expectUrgent) {
            $this->assertSame('critical', $result['severity'], "Expected severity:critical for: {$message}");
            $this->assertStringContainsString('9369 2800', $result['recommended_action']);
        }
    }

    public function test_bare_fever_word_without_temperature_is_not_flagged(): void
    {
        // The exact over-trigger the numeric refactor fixes: a 'fever' mention with
        // no temperature at/above threshold must NOT escalate.
        $result = $this->detector()->evaluate('I was worried about a fever earlier but I feel completely fine now.');

        $this->assertFalse($result['is_urgent']);
        $this->assertSame('low', $result['severity']);
    }

    public function test_non_urgent_message_is_not_flagged(): void
    {
        // Flap colour change post-discharge is explicitly NOT urgent (surgeon rule).
        $result = $this->detector()->evaluate(
            'I have noticed a slight colour change in the flap since I got home, no other symptoms.'
        );

        $this->assertFalse($result['is_urgent']);
        $this->assertSame('low', $result['severity']);
    }

    /**
     * Structural guard: every URGENT trigger declared in the authoritative prompt
     * must be covered by the executed path. Four triggers are covered by
     * CRITICAL_KEYWORDS entries; the fever trigger is covered by the numeric
     * FEVER_THRESHOLD_C parser (not a keyword). If the prompt and the executed list
     * ever diverge again, this test fails.
     */
    public function test_prompt_urgent_triggers_are_all_covered_by_executed_path(): void
    {
        $promptPath = base_path('prompts/escalation-detector.md');
        $this->assertFileExists($promptPath);
        $prompt = file_get_contents($promptPath);

        // Extract the "### URGENT ..." bullet list from the prompt.
        $this->assertMatchesRegularExpression('/###\s+URGENT/i', $prompt, 'Prompt is missing the URGENT section');
        $urgentBlock = preg_split('/###\s+URGENT[^\n]*\n/i', $prompt, 2)[1] ?? '';
        $urgentBlock = preg_split('/\n###\s+/', $urgentBlock, 2)[0] ?? '';
        $triggers = [];
        foreach (preg_split('/\n/', $urgentBlock) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '- ')) {
                $triggers[] = strtolower(substr($line, 2));
            }
        }
        $this->assertCount(5, $triggers, 'Expected exactly 5 surgeon URGENT triggers in the prompt');

        // Keyword coverage for the four phrase-based triggers. The fever trigger is
        // handled separately (numeric threshold) — see below. If a new trigger is
        // added to the prompt, this map must be extended too, forcing prompt/code
        // to move together.
        $keywordCoverage = [
            'breathing' => ['difficulty breathing', 'shortness of breath', 'chest pain'],
            'swelling' => ['severe swelling', 'sudden swelling', 'haematoma'],
            'bleeding' => ['uncontrolled bleeding', 'severe bleeding'],
            'wound' => ['wound opening', 'wound separation', 'dehiscence'],
        ];

        $ref = new ReflectionClass(EscalationDetector::class);
        $keywords = array_map('strtolower', $ref->getConstant('CRITICAL_KEYWORDS'));

        // The prompt states the fever threshold; the runtime constant must mirror it.
        $this->assertMatchesRegularExpression(
            '/fever above 38\.5\s*c/i',
            $prompt,
            'Prompt no longer states the 38.5C fever threshold the constant mirrors'
        );
        $this->assertSame(38.5, EscalationDetector::FEVER_THRESHOLD_C);

        foreach ($triggers as $trigger) {
            if (str_contains($trigger, 'fever')) {
                // Covered by the numeric parser, asserted behaviourally: a temperature
                // at the threshold fires, one just below does not.
                $this->assertTrue(
                    $this->detector()->evaluate('My temperature is 38.5C.')['is_urgent'],
                    "Fever trigger not covered by the executed numeric path: '{$trigger}'"
                );
                $this->assertFalse(
                    $this->detector()->evaluate('My temperature is 38.4C.')['is_urgent'],
                    'Fever parser must not fire below the threshold'
                );

                continue;
            }

            $matchedGroup = null;
            foreach ($keywordCoverage as $anchor => $needed) {
                if (str_contains($trigger, $anchor)) {
                    $matchedGroup = $needed;
                    break;
                }
            }
            $this->assertNotNull(
                $matchedGroup,
                "Prompt URGENT trigger has no coverage mapping (prompt/code drift): '{$trigger}'"
            );
            $covered = false;
            foreach ($matchedGroup as $kw) {
                if (in_array($kw, $keywords, true)) {
                    $covered = true;
                    break;
                }
            }
            $this->assertTrue(
                $covered,
                "No CRITICAL_KEYWORDS entry covers surgeon URGENT trigger: '{$trigger}'"
            );
        }
    }
}
