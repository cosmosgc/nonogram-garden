<?php

namespace App\Services\Nonogram;

use App\Models\PuzzleRevision;
use App\Services\Nonogram\Rulesets\RulesetRegistry;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PuzzleStartHintService
{
    public function __construct(private readonly RulesetRegistry $rulesets) {}

    /** @return array{row_index:int,column_index:int,row_cells:string,column_cells:string}|null */
    public function forRevision(Request $request, PuzzleRevision $revision): ?array
    {
        try {
            $ruleset = $this->rulesets->resolve($revision->mode, $revision->rules_version);
        } catch (InvalidArgumentException) {
            return null;
        }
        if (! $ruleset->supportsStartHint($revision->width, $revision->height)) {
            return null;
        }

        $key = "nonogram:start-hint:{$revision->puzzle_id}:{$revision->id}";
        $selection = $request->session()->get($key);
        if (! is_array($selection)
            || ! isset($selection['row_index'], $selection['column_index'])
            || ! is_int($selection['row_index']) || ! is_int($selection['column_index'])
            || $selection['row_index'] < 0 || $selection['row_index'] >= $revision->height
            || $selection['column_index'] < 0 || $selection['column_index'] >= $revision->width) {
            $selection = [
                'row_index' => random_int(0, $revision->height - 1),
                'column_index' => random_int(0, $revision->width - 1),
            ];
            $request->session()->put($key, $selection);
        }

        $rowStart = $selection['row_index'] * $revision->width;
        $rowCells = substr($revision->solution_data, $rowStart, $revision->width);
        $columnCells = '';
        for ($row = 0; $row < $revision->height; $row++) {
            $columnCells .= $revision->solution_data[$row * $revision->width + $selection['column_index']];
        }

        return [...$selection, 'row_cells' => $rowCells, 'column_cells' => $columnCells];
    }
}
