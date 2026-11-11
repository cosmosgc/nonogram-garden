<?php

namespace App\Services\Nonogram\Rulesets;

/**
 * Server-side contract for one versioned puzzle ruleset.
 *
 * Methods that depend on the meaning of a cell accept an optional palette. Classic
 * ignores it; palette-aware rulesets require it and reject documents without one.
 */
interface PuzzleRuleset
{
    public function id(): string;

    public function version(): int;

    /**
     * Convert an untrusted cell document into the canonical solution string.
     *
     * @param  array<string, mixed>  $document
     */
    public function canonicalCells(array $document, int $width, int $height): string;

    /**
     * Generate the row and column clues from a canonical solution.
     *
     * @param  list<array{name: string, hex: string}>|null  $palette
     * @return array{rows: list<list<int>>|list<list<array{0: int, 1: int}>>, columns: list<list<int>>|list<list<array{0: int, 1: int}>>}
     */
    public function clues(string $cells, int $width, int $height, ?array $palette = null): array;

    /**
     * Analyze the clue set for a solution count.
     *
     * @param  list<list<int>>|list<list<array{0: int, 1: int}>>  $rowClues
     * @param  list<list<int>>|list<list<array{0: int, 1: int}>>  $columnClues
     * @param  list<array{name: string, hex: string}>|null  $palette
     */
    public function analyze(int $width, int $height, array $rowClues, array $columnClues, ?array $palette = null): string;

    /**
     * Compare saved player marks with the canonical solution.
     *
     * @param  list<array{name: string, hex: string}>|null  $palette
     */
    public function matchesSolution(string $marks, string $cells, ?array $palette = null): bool;

    /** Decision: whether a start hint may be issued for this board. */
    public function supportsStartHint(int $width, int $height): bool;

    /**
     * Build the solution hash for a canonical solution.
     *
     * @param  list<array{name: string, hex: string}>|null  $palette
     */
    public function hash(string $cells, int $width, int $height, ?array $palette = null): string;

    /**
     * Whether the ruleset understands the supplied cell document version.
     *
     * @param  array<string, mixed>  $document
     */
    public function supportsDocument(array $document): bool;

    /**
     * @param  list<array{name: string, hex: string}>|null  $palette
     * @return array<string, mixed>
     */
    public function document(string $cells, int $width, int $height, ?array $palette = null): array;
}
