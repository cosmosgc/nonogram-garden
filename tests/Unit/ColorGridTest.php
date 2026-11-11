<?php

namespace Tests\Unit;

use App\Services\Nonogram\ColorGrid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColorGridTest extends TestCase
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
        $grid = ColorGrid::fromArray($this->document($case));
        $clues = $grid->clues();

        self::assertSame($case['rows'], $clues['rows']);
        self::assertSame($case['columns'], $clues['columns']);
    }

    public function test_round_trips_the_palette_and_cells_deterministically(): void
    {
        $grid = ColorGrid::fromArray($this->document(self::fixture()['cases'][0]));
        $document = $grid->toArray();

        self::assertSame('color', $document['mode']);
        self::assertSame(1, $document['rules_version']);
        self::assertSame(self::fixture()['palette'], $document['palette']);
        self::assertSame($grid->hash(), ColorGrid::fromArray($document)->hash());
    }

    public function test_hashing_changes_when_a_palette_or_cell_changes(): void
    {
        $document = $this->document(self::fixture()['cases'][0]);
        $grid = ColorGrid::fromArray($document);

        $recoloured = $document;
        $recoloured['palette'][0]['hex'] = '#A93226';
        self::assertNotSame($grid->hash(), ColorGrid::fromArray($recoloured)->hash());

        $recolouredCell = $document;
        $recolouredCell['cells'] = '1112';
        self::assertNotSame($grid->hash(), ColorGrid::fromArray($recolouredCell)->hash());
    }

    public function test_accepts_a_single_colour_palette(): void
    {
        $grid = ColorGrid::fromArray([
            'mode' => 'color',
            'width' => 1,
            'height' => 1,
            'cells' => '1',
            'palette' => [['name' => 'Ink', 'hex' => '#17231F']],
        ]);

        self::assertSame(['rows' => [[[1, 1]]], 'columns' => [[[1, 1]]]], $grid->clues());
    }

    public function test_rejects_a_cell_colour_missing_from_the_palette(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColorGrid::fromArray($this->document(['width' => 2, 'height' => 1, 'cells' => '13']));
    }

    public function test_rejects_more_than_eight_palette_colours(): void
    {
        $palette = [];
        for ($index = 1; $index <= 9; $index++) {
            $palette[] = ['name' => "Colour {$index}", 'hex' => sprintf('#%06X', $index * 0x111111)];
        }

        $this->expectException(InvalidArgumentException::class);
        ColorGrid::fromArray(['mode' => 'color', 'width' => 1, 'height' => 1, 'cells' => '1', 'palette' => $palette]);
    }

    public function test_rejects_invalid_palette_entries(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColorGrid::fromArray(['mode' => 'color', 'width' => 1, 'height' => 1, 'cells' => '1', 'palette' => [['name' => 'Bad', 'hex' => '#abcdef']]]);
    }

    public function test_rejects_duplicate_palette_hex_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColorGrid::fromArray([
            'mode' => 'color',
            'width' => 1,
            'height' => 1,
            'cells' => '1',
            'palette' => [['name' => 'One', 'hex' => '#C0392B'], ['name' => 'Two', 'hex' => '#C0392B']],
        ]);
    }

    public function test_rejects_unknown_rules_version(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ColorGrid::fromArray([
            'mode' => 'color',
            'rules_version' => 2,
            'width' => 1,
            'height' => 1,
            'cells' => '1',
            'palette' => [['name' => 'Ink', 'hex' => '#17231F']],
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function document(array $overrides): array
    {
        $fixture = self::fixture();

        return array_merge([
            'mode' => $fixture['mode'],
            'width' => 1,
            'height' => 1,
            'cells' => '1',
            'palette' => $fixture['palette'],
        ], $overrides);
    }

    /** @return array{mode: string, rules_version: int, palette: list<array{name: string, hex: string}>, cases: list<array<string, mixed>>} */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__.'/../Fixtures/rulesets/color-v1.json');
        self::assertIsString($raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }
}
