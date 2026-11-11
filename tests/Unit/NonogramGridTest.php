<?php

namespace Tests\Unit;

use App\Services\Nonogram\Grid;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NonogramGridTest extends TestCase
{
    public function test_generates_row_and_column_clues_for_rectangular_grid(): void
    {
        $grid = Grid::fromArray(['version' => 1, 'width' => 4, 'height' => 3, 'cells' => '011010011000']);

        self::assertSame([[2], [1, 1], [1]], $grid->clues()['rows']);
        self::assertSame([[2], [1], [1], [1]], $grid->clues()['columns']);
    }

    public function test_empty_and_full_lines_have_expected_clues(): void
    {
        self::assertSame([[0], [0]], Grid::fromArray(['width' => 2, 'height' => 2, 'cells' => '0000'])->clues()['rows']);
        self::assertSame([[2], [2]], Grid::fromArray(['width' => 2, 'height' => 2, 'cells' => '1111'])->clues()['columns']);
    }

    public function test_single_cell_and_alternating_lines_have_expected_clues(): void
    {
        self::assertSame(['rows' => [[1]], 'columns' => [[1]]], Grid::fromArray(['width' => 1, 'height' => 1, 'cells' => '1'])->clues());
        self::assertSame([[1, 1, 1]], Grid::fromArray(['width' => 5, 'height' => 1, 'cells' => '10101'])->clues()['rows']);
    }

    public function test_maximum_grid_round_trip_and_clue_generation_stay_within_regression_budget(): void
    {
        $document = ['version' => 1, 'width' => 30, 'height' => 30, 'cells' => str_repeat('10100', 180)];
        $startedAt = hrtime(true);
        $grid = Grid::fromArray($document);
        $encoded = $grid->toArray();
        $clues = $grid->clues();
        $elapsedMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertSame($document, $encoded);
        self::assertCount(30, $clues['rows']);
        self::assertCount(30, $clues['columns']);
        self::assertLessThan(2000, $elapsedMilliseconds, 'Maximum 30×30 grid parsing and clue generation exceeded the 2-second regression budget.');
    }

    #[DataProvider('invalidDocuments')]
    public function test_rejects_invalid_documents(array $document): void
    {
        $this->expectException(InvalidArgumentException::class);
        Grid::fromArray($document);
    }

    public static function invalidDocuments(): array
    {
        return [
            'unknown version' => [['version' => 2, 'width' => 1, 'height' => 1, 'cells' => '0']],
            'bad dimensions' => [['width' => 31, 'height' => 1, 'cells' => str_repeat('0', 31)]],
            'zero dimension' => [['width' => 0, 'height' => 1, 'cells' => '']],
            'noninteger dimension' => [['width' => '2', 'height' => 2, 'cells' => '0000']],
            'wrong count' => [['width' => 2, 'height' => 2, 'cells' => '000']],
            'nonbinary' => [['width' => 1, 'height' => 1, 'cells' => 'x']],
            'too long' => [['width' => 1, 'height' => 1, 'cells' => str_repeat('0', 901)]],
        ];
    }

    public function test_hash_is_deterministic_and_shape_sensitive(): void
    {
        $first = Grid::fromArray(['width' => 2, 'height' => 2, 'cells' => '0101']);
        $same = Grid::fromArray(['version' => 1, 'width' => 2, 'height' => 2, 'cells' => '0101']);
        self::assertSame($first->hash(), $same->hash());
        self::assertNotSame($first->hash(), Grid::fromArray(['width' => 1, 'height' => 4, 'cells' => '0101'])->hash());
    }
}
