<?php

namespace Tests\Unit;

use App\Services\Nonogram\UniquenessSolver;
use PHPUnit\Framework\TestCase;

class UniquenessSolverTest extends TestCase
{
    public function test_reports_a_unique_puzzle(): void
    {
        $solver = new UniquenessSolver;

        self::assertSame('unique', $solver->analyze(1, 1, [[1]], [[1]]));
    }

    public function test_stops_after_finding_two_solutions(): void
    {
        $solver = new UniquenessSolver;

        self::assertSame('ambiguous', $solver->analyze(2, 2, [[1], [1]], [[1], [1]]));
    }

    public function test_reports_inconsistent_clues(): void
    {
        $solver = new UniquenessSolver;

        self::assertSame('inconsistent', $solver->analyze(2, 2, [[2], [2]], [[1], [1]]));
        self::assertSame('inconsistent', $solver->analyze(2, 2, [[3], [0]], [[1], [1]]));
    }

    public function test_distinguishes_exhausted_search_from_verified_uniqueness(): void
    {
        $solver = new UniquenessSolver(nodeLimit: 0);

        self::assertSame('unknown', $solver->analyze(1, 1, [[1]], [[1]]));
    }

    public function test_candidate_generation_has_a_global_candidate_budget(): void
    {
        $solver = new UniquenessSolver(candidateLimit: 2);

        self::assertSame('unknown', $solver->analyze(2, 2, [[1], [1]], [[1], [1]]));
    }

    public function test_empty_lines_also_consume_the_global_candidate_budget(): void
    {
        $solver = new UniquenessSolver(candidateLimit: 1);

        self::assertSame('unknown', $solver->analyze(1, 1, [[0]], [[0]]));
    }

    public function test_returns_unknown_when_candidate_generation_exceeds_the_memory_budget(): void
    {
        $clues = array_fill(0, 30, [1, 1, 1, 1, 1, 1, 1]);
        $solver = new UniquenessSolver(timeLimitMilliseconds: 10_000, memoryLimitBytes: 1024);

        self::assertSame('unknown', $solver->analyze(30, 30, $clues, $clues));
    }

    public function test_maximum_dimension_candidate_generation_is_time_bounded(): void
    {
        $clues = array_fill(0, 30, [1, 1, 1, 1, 1, 1, 1]);
        $solver = new UniquenessSolver(timeLimitMilliseconds: 50, candidateLimit: 20_000);
        $startedAt = hrtime(true);

        self::assertSame('unknown', $solver->analyze(30, 30, $clues, $clues));
        self::assertLessThan(2000, (hrtime(true) - $startedAt) / 1_000_000);
    }
}
