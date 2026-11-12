<?php

namespace Tests\Unit;

use App\Services\Nonogram\ColorUniquenessSolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Proves the palette-aware solver matches the shared Color v1 fixture. */
class ColorUniquenessSolverTest extends TestCase
{
    /** @return array<string, array{case: array<string, mixed>}> */
    public static function solverCases(): array
    {
        $cases = [];
        foreach (self::fixture()['solver_cases'] as $case) {
            $cases[$case['name']] = ['case' => $case];
        }

        return $cases;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('solverCases')]
    public function test_solver_matches_the_shared_fixture(array $case): void
    {
        $limits = $case['limits'] ?? [];
        $solver = new ColorUniquenessSolver(
            nodeLimit: $limits['nodeLimit'] ?? 100_000,
            timeLimitMilliseconds: $limits['timeLimitMilliseconds'] ?? 1500,
            candidateLimitPerLine: $limits['candidateLimitPerLine'] ?? 50_000,
            candidateLimit: $limits['candidateLimit'] ?? 20_000,
        );

        self::assertSame(
            $case['expected'],
            $solver->analyze($case['width'], $case['height'], $case['rows'], $case['columns'], $case['color_count']),
            $case['name'],
        );
    }

    public function test_rejects_a_palette_count_beyond_the_supported_range(): void
    {
        $solver = new ColorUniquenessSolver;

        self::assertSame('inconsistent', $solver->analyze(1, 1, [[[1, 1]]], [[[1, 1]]], 0));
        self::assertSame('inconsistent', $solver->analyze(1, 1, [[[1, 1]]], [[[1, 1]]], 9));
    }

    public function test_returns_unknown_when_candidate_generation_exceeds_the_memory_budget(): void
    {
        $clues = array_fill(0, 30, [[1, 1], [1, 1], [1, 1], [1, 1], [1, 1], [1, 1], [1, 1]]);
        $solver = new ColorUniquenessSolver(timeLimitMilliseconds: 10_000, memoryLimitBytes: 1024);

        self::assertSame('unknown', $solver->analyze(30, 30, $clues, $clues, 1));
    }

    /** @return array{solver_cases: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__.'/../Fixtures/rulesets/color-v1.json');
        self::assertIsString($raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
