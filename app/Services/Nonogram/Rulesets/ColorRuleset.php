<?php

namespace App\Services\Nonogram\Rulesets;

use App\Services\Nonogram\ColorGrid;
use App\Services\Nonogram\ColorUniquenessSolver;

/**
 * Palette-aware Color Picross ruleset, version 1.
 *
 * Player marks reuse the solution digit alphabet: "0" unknown, "1"–"8" filled
 * with that palette colour, and "9" crossed out. A fill only matches when its
 * colour ID equals the solution cell's ID.
 *
 * Published Color revisions are supported through palette-aware authoring,
 * player marks, progress validation, and exact server-side completion.
 */
final class ColorRuleset implements PuzzleRuleset
{
    public const MODE = 'color';

    public const VERSION = 1;

    public const CROSSED_MARK = '9';

    public function __construct(private readonly ColorUniquenessSolver $solver) {}

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
        return ColorGrid::fromArray([
            'mode' => self::MODE,
            'rules_version' => self::VERSION,
            'width' => $width,
            'height' => $height,
            'cells' => $document['cells'] ?? null,
            'palette' => $document['palette'] ?? null,
        ])->cells;
    }

    public function clues(string $cells, int $width, int $height, ?array $palette = null): array
    {
        return $this->grid($cells, $width, $height, $palette)->clues();
    }

    public function analyze(int $width, int $height, array $rowClues, array $columnClues, ?array $palette = null): string
    {
        return $this->solver->analyze($width, $height, $rowClues, $columnClues, count($palette ?? []));
    }

    public function matchesSolution(string $marks, string $cells, ?array $palette = null): bool
    {
        if (strlen($marks) !== strlen($cells)) {
            return false;
        }

        for ($index = 0; $index < strlen($cells); $index++) {
            $solution = $cells[$index];
            $mark = $marks[$index];

            if ($solution === '0') {
                if ($mark !== '0' && $mark !== self::CROSSED_MARK) {
                    return false;
                }

                continue;
            }

            if ($mark !== $solution) {
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
        return $this->grid($cells, $width, $height, $palette)->hash();
    }

    public function supportsDocument(array $document): bool
    {
        return ($document['mode'] ?? self::MODE) === self::MODE
            && ($document['rules_version'] ?? self::VERSION) === self::VERSION;
    }

    public function document(string $cells, int $width, int $height, ?array $palette = null): array
    {
        return $this->grid($cells, $width, $height, $palette)->toArray();
    }

    /** @param  list<array{name: string, hex: string}>|null  $palette */
    private function grid(string $cells, int $width, int $height, ?array $palette): ColorGrid
    {
        return ColorGrid::fromArray([
            'mode' => self::MODE,
            'rules_version' => self::VERSION,
            'width' => $width,
            'height' => $height,
            'cells' => $cells,
            'palette' => $palette,
        ]);
    }
}
