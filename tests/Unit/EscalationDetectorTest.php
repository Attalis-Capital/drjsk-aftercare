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
 * keyword list never diverge again (the exact gap the #2 review found: the PR body
 * verified the PROMPT while the executed path ignored three of the five triggers).
 */
class EscalationDetectorTest extends TestCase
{
    private function detector(): EscalationDetector
    {
        // The keyword fast-path does not call the AI client, so real
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
            'fever above 38.5C' => ['I have a fever and my temperature of 39 is not coming down.'],
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
     * must be covered by at least one runtime CRITICAL_KEYWORDS entry. If the prompt
     * and the executed keyword list ever diverge again, this test fails.
     */
    public function test_prompt_urgent_triggers_are_all_covered_by_runtime_keywords(): void
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

        // Map each prompt trigger to the concrete keyword tokens that must exist in
        // CRITICAL_KEYWORDS to cover it. If a new trigger is added to the prompt,
        // this map must be extended too - forcing prompt/code to move together.
        $coverage = [
            'breathing' => ['difficulty breathing', 'shortness of breath', 'chest pain'],
            'swelling' => ['severe swelling', 'sudden swelling', 'haematoma'],
            'fever' => ['fever'],
            'bleeding' => ['uncontrolled bleeding', 'severe bleeding'],
            'wound' => ['wound opening', 'wound separation', 'dehiscence'],
        ];

        $ref = new ReflectionClass(EscalationDetector::class);
        $keywords = array_map('strtolower', $ref->getConstant('CRITICAL_KEYWORDS'));

        foreach ($triggers as $trigger) {
            $matchedGroup = null;
            foreach ($coverage as $anchor => $needed) {
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
