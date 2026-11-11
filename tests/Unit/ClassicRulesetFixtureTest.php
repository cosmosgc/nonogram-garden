<?php

namespace Tests\Unit;

use App\Services\Nonogram\ColorUniquenessSolver;
use App\Services\Nonogram\Rulesets\ClassicRuleset;
use App\Services\Nonogram\Rulesets\ColorRuleset;
use App\Services\Nonogram\Rulesets\RulesetRegistry;
use App\Services\Nonogram\UniquenessSolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Proves the PHP Classic ruleset matches the shared cross-language fixture. */
class ClassicRulesetFixtureTest extends TestCase
{
    /** @return array<string, array{case: array<string, mixed>}> */
    public static function clueCases(): array
    {
        $fixture = self::fixture();
        $cases = [];
        foreach ($fixture['cases'] as $case) {
            $cases[$case['name']] = ['case' => $case];
        }

        return $cases;
    }

    /** @param array<string, mixed> $case */
    #[DataProvider('clueCases')]
    public function test_generated_clues_match_the_shared_fixture(array $case): void
    {
        $ruleset = $this->ruleset();
        $document = [
            'version' => self::fixture()['format_version'],
            'width' => $case['width'],
            'height' => $case['height'],
            'cells' => $case['cells'],
        ];

        $cells = $ruleset->canonicalCells($document, $case['width'], $case['height']);
        $clues = $ruleset->clues($cells, $case['width'], $case['height']);

        self::assertSame($case['cells'], $cells);
        self::assertSame($case['rows'], $clues['rows']);
        self::assertSame($case['columns'], $clues['columns']);
        self::assertSame($document, $ruleset->document($cells, $case['width'], $case['height']));
    }

    public function test_classic_fixture_ruleset_remains_registered(): void
    {
        $fixture = self::fixture();
        $registry = $this->rulesetRegistry();

        self::assertContains(['id' => $fixture['mode'], 'version' => $fixture['rules_version']], $registry->supported());
    }

    /** @return array{mode: string, rules_version: int, format_version: int, cases: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__.'/../Fixtures/rulesets/classic-v1.json');
        self::assertIsString($raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function rulesetRegistry(): RulesetRegistry
    {
        return new RulesetRegistry(new ClassicRuleset(new UniquenessSolver), new ColorRuleset(new ColorUniquenessSolver));
    }

    private function ruleset(): ClassicRuleset
    {
        return new ClassicRuleset(new UniquenessSolver);
    }
}
