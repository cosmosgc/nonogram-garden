<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleProgress;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use App\Services\Nonogram\Rulesets\RulesetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CompletePuzzleController extends Controller
{
    public function __invoke(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings, RulesetRegistry $rulesets): JsonResponse
    {
        if ($revision->puzzle_id !== $puzzle->id || ! $settings->canPlayPuzzle($puzzle)) {
            throw new NotFoundHttpException;
        }

        try {
            $ruleset = $rulesets->resolve($revision->mode, $revision->rules_version);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'This puzzle rules version cannot be verified.'], 422);
        }

        return DB::transaction(function () use ($request, $revision, $ruleset): JsonResponse {
            $progress = PuzzleProgress::query()
                ->where('user_id', $request->user()->id)
                ->where('puzzle_revision_id', $revision->id)
                ->lockForUpdate()
                ->first();
            if ($progress === null) {
                return response()->json(['message' => 'Save progress before requesting completion verification.'], 422);
            }
            if ($progress->completed_at !== null) {
                return response()->json(['completed' => true, 'already_completed' => true, 'completed_at' => $progress->completed_at->toIso8601String()]);
            }

            if (! $ruleset->matchesSolution($progress->marks, $revision->solution_data, $revision->palette)) {
                $progress->mistake_count++;
                $progress->version++;
                $progress->save();

                return response()->json([
                    'message' => 'The saved marks do not match this puzzle yet.',
                    'completed' => false,
                    'progress_version' => $progress->version,
                    'mistake_count' => $progress->mistake_count,
                ], 422);
            }

            $progress->completed_at = now();
            $progress->save();

            return response()->json(['completed' => true, 'already_completed' => false, 'completed_at' => $progress->completed_at->toIso8601String()]);
        });
    }
}
