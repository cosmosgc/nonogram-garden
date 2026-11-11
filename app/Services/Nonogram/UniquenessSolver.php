<?php

namespace App\Services\Nonogram;

/** Bounded binary-nonogram solver. Returns unknown when its search budget is exhausted. */
final class UniquenessSolver
{
    private int $nodes = 0;

    private float $deadline;

    private int $solutions = 0;

    private bool $exhausted = false;

    private int $candidatesGenerated = 0;

    public function __construct(
        private readonly int $nodeLimit = 100_000,
        private readonly int $timeLimitMilliseconds = 1500,
        private readonly int $candidateLimitPerLine = 50_000,
        private readonly int $candidateLimit = 20_000,
    ) {}

    /** @param list<list<int>> $rowClues @param list<list<int>> $columnClues */
    public function analyze(int $width, int $height, array $rowClues, array $columnClues): string
    {
        if ($width < 1 || $height < 1 || $width > 30 || $height > 30
            || count($rowClues) !== $height || count($columnClues) !== $width) {
            return 'inconsistent';
        }

        $this->nodes = 0;
        $this->solutions = 0;
        $this->exhausted = false;
        $this->candidatesGenerated = 0;
        $this->deadline = microtime(true) + max(0, $this->timeLimitMilliseconds) / 1000;

        $rows = [];
        foreach ($rowClues as $clues) {
            $rows[] = $this->lineCandidates($clues, $width);
        }
        $columns = [];
        foreach ($columnClues as $clues) {
            $columns[] = $this->lineCandidates($clues, $height);
        }
        if ($this->exhausted) {
            return 'unknown';
        }
        if (in_array([], $rows, true) || in_array([], $columns, true)) {
            return 'inconsistent';
        }

        $this->search($rows, $columns);

        if ($this->solutions >= 2) {
            return 'ambiguous';
        }
        if ($this->exhausted) {
            return 'unknown';
        }

        return $this->solutions === 1 ? 'unique' : 'inconsistent';
    }

    /** @param list<int> $clues @return list<string> */
    private function lineCandidates(array $clues, int $length): array
    {
        if ($clues === [0]) {
            if ($this->candidatesGenerated >= $this->candidateLimit || microtime(true) >= $this->deadline) {
                $this->exhausted = true;

                return [];
            }
            $this->candidatesGenerated++;

            return [str_repeat('0', $length)];
        }
        if ($clues === [] || array_filter($clues, fn ($run) => ! is_int($run) || $run < 1) !== []) {
            return [];
        }
        if (array_sum($clues) + count($clues) - 1 > $length) {
            return [];
        }

        $patterns = [];
        $this->placeRuns($clues, 0, 0, array_fill(0, $length, '0'), $length, $patterns);

        return array_map(fn (array $line) => implode('', $line), $patterns);
    }

    /** @param list<int> $clues @param list<string> $line @param list<list<string>> $patterns */
    private function placeRuns(array $clues, int $runIndex, int $cursor, array $line, int $length, array &$patterns): void
    {
        if ($this->exhausted) {
            return;
        }
        if (count($patterns) >= $this->candidateLimitPerLine
            || $this->candidatesGenerated >= $this->candidateLimit
            || microtime(true) >= $this->deadline) {
            $this->exhausted = true;

            return;
        }
        if ($runIndex === count($clues)) {
            $patterns[] = $line;
            $this->candidatesGenerated++;

            return;
        }

        $remainingRuns = array_slice($clues, $runIndex);
        $minimumTail = array_sum($remainingRuns) + count($remainingRuns) - 1;
        $latestStart = $length - $minimumTail;
        for ($start = $cursor; $start <= $latestStart; $start++) {
            $next = $line;
            for ($cell = $start; $cell < $start + $clues[$runIndex]; $cell++) {
                $next[$cell] = '1';
            }
            $this->placeRuns($clues, $runIndex + 1, $start + $clues[$runIndex] + 1, $next, $length, $patterns);
        }
    }

    /** @param list<list<string>> $rows @param list<list<string>> $columns */
    private function search(array $rows, array $columns): void
    {
        if ($this->solutions >= 2 || $this->exhausted) {
            return;
        }
        if (++$this->nodes > $this->nodeLimit || microtime(true) >= $this->deadline) {
            $this->exhausted = true;

            return;
        }
        if (! $this->propagate($rows, $columns)) {
            return;
        }

        $branch = null;
        $smallest = PHP_INT_MAX;
        foreach ($rows as $index => $candidates) {
            if (count($candidates) > 1 && count($candidates) < $smallest) {
                $smallest = count($candidates);
                $branch = ['axis' => 'row', 'index' => $index];
            }
        }
        foreach ($columns as $index => $candidates) {
            if (count($candidates) > 1 && count($candidates) < $smallest) {
                $smallest = count($candidates);
                $branch = ['axis' => 'column', 'index' => $index];
            }
        }

        if ($branch === null) {
            $this->solutions++;

            return;
        }

        $axis = $branch['axis'];
        $index = $branch['index'];
        $candidates = $axis === 'row' ? $rows[$index] : $columns[$index];
        foreach ($candidates as $candidate) {
            if ($axis === 'row') {
                $nextRows = $rows;
                $nextColumns = $columns;
                $nextRows[$index] = [$candidate];
            } else {
                $nextRows = $rows;
                $nextColumns = $columns;
                $nextColumns[$index] = [$candidate];
            }
            $this->search($nextRows, $nextColumns);
            if ($this->solutions >= 2 || $this->exhausted) {
                return;
            }
        }
    }

    /** @param list<list<string>> $rows @param list<list<string>> $columns */
    private function propagate(array &$rows, array &$columns): bool
    {
        do {
            $changed = false;
            foreach ($rows as $y => $rowCandidates) {
                if (microtime(true) >= $this->deadline) {
                    $this->exhausted = true;

                    return false;
                }
                for ($x = 0; $x < count($columns); $x++) {
                    $allowed = [];
                    foreach ($rowCandidates as $candidate) {
                        $allowed[$candidate[$x]] = true;
                    }
                    $filtered = array_values(array_filter($columns[$x], fn ($candidate) => isset($allowed[$candidate[$y]])));
                    if ($filtered === []) {
                        return false;
                    }
                    if (count($filtered) !== count($columns[$x])) {
                        $columns[$x] = $filtered;
                        $changed = true;
                    }
                }
            }
            foreach ($columns as $x => $columnCandidates) {
                if (microtime(true) >= $this->deadline) {
                    $this->exhausted = true;

                    return false;
                }
                for ($y = 0; $y < count($rows); $y++) {
                    $allowed = [];
                    foreach ($columnCandidates as $candidate) {
                        $allowed[$candidate[$y]] = true;
                    }
                    $filtered = array_values(array_filter($rows[$y], fn ($candidate) => isset($allowed[$candidate[$x]])));
                    if ($filtered === []) {
                        return false;
                    }
                    if (count($filtered) !== count($rows[$y])) {
                        $rows[$y] = $filtered;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return true;
    }
}
