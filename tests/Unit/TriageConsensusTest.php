<?php

namespace Tests\Unit;

use App\Services\AI\TriageConsensus;
use App\Services\AI\TriageVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Truth-table tests for the D1/D2 sensitivity-first OR-gate (mission #1701).
 *
 * Proves the same safety behaviour as eval/test_ensemble.py, in PHP:
 *   - single-voter urgent -> urgent
 *   - either low-confidence needs-review -> urgent
 *   - either call fails -> urgent
 *   - both calls fail -> urgent (triage unavailable)
 *   - only both-agree-confident reaches needs-review
 * Plus: the output enum is exactly two-valued (no discharge/normal, D3).
 */
class TriageConsensusTest extends TestCase
{
    private const FLOOR = 0.7;

    private function consensus(): TriageConsensus
    {
        return new TriageConsensus;
    }

    private function vote(?string $class, float $conf, bool $ok = true): array
    {
        return ['class' => $class, 'confidence' => $conf, 'ok' => $ok];
    }

    /**
     * @return array<string, array{0: array, 1: array, 2: string}>
     */
    public static function orGateCases(): array
    {
        $U = TriageVerdict::Urgent->value;
        $N = TriageVerdict::NeedsReview->value;
        $v = fn (?string $c, float $conf, bool $ok = true) => ['class' => $c, 'confidence' => $conf, 'ok' => $ok];

        return [
            'both_needs_review_confident_only_path_to_needs_review' => [$v($N, 0.9), $v($N, 0.9), $N],
            'primary_urgent_forces_urgent' => [$v($U, 0.9), $v($N, 0.9), $U],
            'secondary_urgent_forces_urgent' => [$v($N, 0.9), $v($U, 0.9), $U],
            'primary_low_confidence_forces_urgent' => [$v($N, 0.5), $v($N, 0.9), $U],
            'secondary_low_confidence_forces_urgent' => [$v($N, 0.9), $v($N, 0.5), $U],
            'confidence_exactly_at_floor_is_confident' => [$v($N, self::FLOOR), $v($N, self::FLOOR), $N],
            'primary_fail_forces_urgent' => [$v(null, 0.0, false), $v($N, 0.9), $U],
            'secondary_fail_forces_urgent' => [$v($N, 0.9), $v(null, 0.0, false), $U],
            'both_fail_forces_urgent_unavailable' => [$v(null, 0.0, false), $v(null, 0.0, false), $U],
        ];
    }

    #[DataProvider('orGateCases')]
    public function test_or_gate_truth_table(array $primary, array $secondary, string $expected): void
    {
        $result = $this->consensus()->decide($primary, $secondary, self::FLOOR);
        $this->assertSame($expected, $result['class']);
    }

    public function test_both_fail_is_marked_unavailable(): void
    {
        $result = $this->consensus()->decide(
            $this->vote(null, 0.0, false),
            $this->vote(null, 0.0, false),
            self::FLOOR
        );

        $this->assertSame(TriageVerdict::Urgent->value, $result['class']);
        $this->assertTrue($result['unavailable']);
        $this->assertSame('both_calls_failed_unavailable', $result['reason']);
    }

    public function test_single_fail_is_or_gate_escalation_not_unavailable(): void
    {
        $result = $this->consensus()->decide(
            $this->vote(null, 0.0, false),
            $this->vote(TriageVerdict::NeedsReview->value, 0.9),
            self::FLOOR
        );

        $this->assertSame(TriageVerdict::Urgent->value, $result['class']);
        $this->assertFalse($result['unavailable']);
        $this->assertContains('primary_fail', $result['escalated_by']);
    }

    public function test_output_enum_is_exactly_two_valued(): void
    {
        // D3: exactly two classes exist. No discharge/normal/fine/healthy.
        $this->assertSame(['urgent', 'needs-review'], TriageVerdict::VALID_CLASSES);
        $this->assertCount(2, TriageVerdict::VALID_CLASSES);
        $this->assertCount(2, TriageVerdict::cases());

        $values = array_map(fn (TriageVerdict $c) => $c->value, TriageVerdict::cases());
        sort($values);
        $expected = ['needs-review', 'urgent'];
        $this->assertSame($expected, $values);

        foreach (['normal', 'discharge', 'fine', 'healthy'] as $forbidden) {
            $this->assertNotContains($forbidden, TriageVerdict::VALID_CLASSES);
        }
    }
}
