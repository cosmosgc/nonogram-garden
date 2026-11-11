<?php

namespace Tests\Unit;

use App\Services\Nonogram\ColorUniquenessSolver;
use App\Services\Nonogram\Rulesets\ClassicRuleset;
use App\Services\Nonogram\Rulesets\ColorRuleset;
use App\Services\Nonogram\Rulesets\RulesetRegistry;
use App\Services\Nonogram\UniquenessSolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class RulesetRegistryTest extends TestCase
{
    public function test_resolves_the_supported_classic_ruleset_as_the_default(): void
    {
        $registry = $this->registry();

        self::assertSame('classic', $registry->default()->id());
        self::assertSame(1, $registry->default()->version());
        self::assertSame([['id' => 'classic', 'version' => 1], ['id' => 'color', 'version' => 1]], $registry->supported());
    }

    public function test_rejects_unsupported_mode_and_rules_version_pairs(): void
    {
        $registry = $this->registry();

        self::assertTrue($registry->supports('color', 1));
        self::assertFalse($registry->supports('color', 2));
        self::assertFalse($registry->supports('classic', 2));
        $this->expectException(InvalidArgumentException::class);
        $registry->resolve('mega', 1);
    }

    public function test_classic_generates_and_matches_only_exact_filled_cells(): void
    {
        $ruleset = $this->registry()->default();

        self::assertSame('0110', $ruleset->canonicalCells(['version' => 1, 'cells' => '0110'], 4, 1));
        self::assertSame(['rows' => [[2]], 'columns' => [[0], [1], [1], [0]]], $ruleset->clues('0110', 4, 1));
        self::assertTrue($ruleset->matchesSolution('0112', '0110'));
        self::assertFalse($ruleset->matchesSolution('0210', '0110'));
        self::assertFalse($ruleset->matchesSolution('011', '0110'));
    }

    public function test_classic_rejects_other_cell_documents_and_suppresses_incomplete_start_hints(): void
    {
        $ruleset = $this->registry()->default();

        self::assertFalse($ruleset->supportsDocument(['version' => 2]));
        self::assertTrue($ruleset->supportsStartHint(2, 2));
        self::assertFalse($ruleset->supportsStartHint(1, 5));
        self::assertFalse($ruleset->supportsStartHint(5, 1));
        $this->expectException(InvalidArgumentException::class);
        $ruleset->canonicalCells(['version' => 1, 'cells' => '0102'], 4, 1);
    }

    private function registry(): RulesetRegistry
    {
        return new RulesetRegistry(new ClassicRuleset(new UniquenessSolver), new ColorRuleset(new ColorUniquenessSolver));
    }
}
