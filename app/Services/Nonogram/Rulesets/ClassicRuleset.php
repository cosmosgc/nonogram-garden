<?php

namespace App\Services\Nonogram\Rulesets;

use App\Services\Nonogram\Grid;
use App\Services\Nonogram\UniquenessSolver;

/** Monochrome Classic ruleset, version 1. */
final class ClassicRuleset implements PuzzleRuleset
{
    public const MODE = 'classic';

    public const VERSION = 1;

    public function __construct(private readonly UniquenessSolver $solver) {}

    public function id(): string
    {
        return self::MODE;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function canonicalCells(array $document, int $width, int $height): string
    {
        return $this->grid($document['cells'] ?? '', $width, $height)->cells;
    }

    public function clues(string $cells, int $width, int $height, ?array $palette = null): array
    {
        return $this->grid($cells, $width, $height)->clues();
    }

    public function analyze(int $width, int $height, array $rowClues, array $columnClues, ?array $palette = null): string
    {
        return $this->solver->analyze($width, $height, $rowClues, $columnClues);
    }

    public function matchesSolution(string $marks, string $cells, ?array $palette = null): bool
    {
        if (strlen($marks) !== strlen($cells)) {
            return false;
        }

        for ($index = 0; $index < strlen($cells); $index++) {
            if (($marks[$index] === '1') !== ($cells[$index] === '1')) {
                return false;
            }
        }

        return true;
    }

    public function supportsStartHint(int $width, int $height): bool
    {
        return $width >= 2 && $height >= 2;
    }

    public function hash(string $cells, int $width, int $height, ?array $palette = null): string
    {
        return $this->grid($cells, $width, $height)->hash();
    }

    public function supportsDocument(array $document): bool
    {
        return ($document['version'] ?? Grid::FORMAT_VERSION) === Grid::FORMAT_VERSION;
    }

    public function document(string $cells, int $width, int $height, ?array $palette = null): array
    {
        return $this->grid($cells, $width, $height)->toArray();
    }

    public function grid(string $cells, int $width, int $height): Grid
    {
        return Grid::fromArray(['version' => Grid::FORMAT_VERSION, 'width' => $width, 'height' => $height, 'cells' => $cells]);
    }
}
