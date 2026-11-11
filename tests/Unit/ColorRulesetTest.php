<?php

namespace Tests\Unit;

use App\Services\Nonogram\ColorUniquenessSolver;
use App\Services\Nonogram\Rulesets\ColorRuleset;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ColorRulesetTest extends TestCase
{
    public function test_clues_match_the_shared_fixture(): void
    {
        $fixture = self::fixture();
        $ruleset = $this->ruleset();

        foreach ($fixture['cases'] as $case) {
            $clues = $ruleset->clues($case['cells'], $case['width'], $case['height'], $fixture['palette']);

            self::assertSame($case['rows'], $clues['rows'], $case['name']);
            self::assertSame($case['columns'], $clues['columns'], $case['name']);
        }
    }

    public function test_matches_only_exact_colour_ids_and_allows_crossed_empty_cells(): void
    {
        $ruleset = $this->ruleset();

        self::assertTrue($ruleset->matchesSolution('1112', '1112'));
        self::assertTrue($ruleset->matchesSolution('1119', '1110'));
        self::assertTrue($ruleset->matchesSolution('9990', '0000'));
        self::assertFalse($ruleset->matchesSolution('2221', '1112'));
        self::assertFalse($ruleset->matchesSolution('1111', '1110'));
        self::assertFalse($ruleset->matchesSolution('111', '1112'));
    }

    public function test_hash_depends_on_palette_and_cells(): void
    {
        $fixture = self::fixture();
        $ruleset = $this->ruleset();
        $hash = $ruleset->hash('1122', 4, 1, $fixture['palette']);
        $recoloured = $fixture['palette'];
        $recoloured[0]['hex'] = '#A93226';

        self::assertSame($hash, $ruleset->hash('1122', 4, 1, $fixture['palette']));
        self::assertNotSame($hash, $ruleset->hash('1122', 4, 1, $recoloured));
        self::assertNotSame($hash, $ruleset->hash('1112', 4, 1, $fixture['palette']));
    }

    public function test_document_round_trips_the_palette(): void
    {
        $fixture = self::fixture();
        $ruleset = $this->ruleset();
        $document = $ruleset->document('1122', 4, 1, $fixture['palette']);

        self::assertSame('color', $document['mode']);
        self::assertSame(1, $document['rules_version']);
        self::assertSame($fixture['palette'], $document['palette']);
        self::assertSame('1122', $ruleset->canonicalCells($document, 4, 1));
    }

    public function test_requires_a_palette_before_generating_clues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ruleset()->clues('1', 1, 1);
    }

    public function test_analyze_uses_the_palette_size(): void
    {
        $ruleset = $this->ruleset();

        self::assertSame(
            'unique',
            $ruleset->analyze(3, 1, [[[2, 1], [1, 2]]], [[[1, 1]], [[1, 1]], [[1, 2]]], self::fixture()['palette']),
        );
        self::assertSame(
            'inconsistent',
            $ruleset->analyze(1, 1, [[[1, 2]]], [[[1, 2]]], [['name' => 'Only', 'hex' => '#C0392B']]),
        );
    }

    public function test_supports_only_its_own_mode_and_version(): void
    {
        $ruleset = $this->ruleset();

        self::assertTrue($ruleset->supportsDocument([]));
        self::assertTrue($ruleset->supportsDocument(['mode' => 'color', 'rules_version' => 1]));
        self::assertFalse($ruleset->supportsDocument(['mode' => 'classic']));
        self::assertFalse($ruleset->supportsDocument(['mode' => 'color', 'rules_version' => 2]));
    }

    /** @return array{palette: list<array{name: string, hex: string}>, cases: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__.'/../Fixtures/rulesets/color-v1.json');
        self::assertIsString($raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function ruleset(): ColorRuleset
    {
        return new ColorRuleset(new ColorUniquenessSolver);
    }
}
