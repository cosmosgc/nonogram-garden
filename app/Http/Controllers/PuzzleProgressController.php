<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavePuzzleProgressRequest;
use App\Models\Puzzle;
use App\Models\PuzzleProgress;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use App\Services\Nonogram\Rulesets\RulesetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PuzzleProgressController extends Controller
{
    public function show(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): JsonResponse
    {
        $this->ensurePlayableRevision($puzzle, $revision, $settings);
        $progress = PuzzleProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('puzzle_revision_id', $revision->id)
            ->first();

        return response()->json([
            'revision_id' => $revision->id,
            'width' => $revision->width,
            'height' => $revision->height,
            'progress' => $progress ? $this->progressData($progress) : [
                'marks' => str_repeat('0', $revision->width * $revision->height),
                'elapsed_seconds' => 0,
                'mistake_count' => 0,
                'version' => 0,
                'completed_at' => null,
                'last_played_at' => null,
            ],
        ]);
    }

    public function save(SavePuzzleProgressRequest $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings, RulesetRegistry $rulesets): JsonResponse
    {
        $this->ensurePlayableRevision($puzzle, $revision, $settings);
        $validated = $request->validated();
        $userId = $request->user()->id;

        return DB::transaction(function () use ($validated, $userId, $revision, $rulesets): JsonResponse {
            $requestedVersion = (int) $validated['version'];
            $existing = PuzzleProgress::query()->where('user_id', $userId)
                ->where('puzzle_revision_id', $revision->id)->exists();
            if (! $existing && $requestedVersion !== 0) {
                return response()->json([
                    'message' => 'Progress changed in another session. Reload before saving to avoid overwriting it.',
                    'current_version' => 0,
                ], 409);
            }

            $now = now();
            DB::table('puzzle_progress')->insertOrIgnore([
                'user_id' => $userId,
                'puzzle_revision_id' => $revision->id,
                'marks' => str_repeat('0', $revision->width * $revision->height),
                'elapsed_seconds' => 0,
                'mistake_count' => 0,
                'version' => 0,
                'completed_at' => null,
                'last_played_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $progress = PuzzleProgress::query()
                ->where('user_id', $userId)
                ->where('puzzle_revision_id', $revision->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($progress->completed_at !== null) {
                return response()->json([
                    'message' => 'This revision is already completed and its progress is locked.',
                    'current_version' => $progress->version,
                ], 409);
            }

            if ($progress->version !== $requestedVersion) {
                return response()->json([
                    'message' => 'Progress changed in another session. Reload before saving to avoid overwriting it.',
                    'current_version' => $progress->version,
                ], 409);
            }

            $progress->fill([
                'marks' => $validated['marks'],
                'elapsed_seconds' => $validated['elapsed_seconds'],
                'version' => $progress->version + 1,
                'last_played_at' => $now,
            ])->save();

            if ($rulesets->supports($revision->mode, $revision->rules_version)) {
                $ruleset = $rulesets->resolve($revision->mode, $revision->rules_version);
                if ($ruleset->matchesSolution($progress->marks, $revision->solution_data, $revision->palette)) {
                    $progress->completed_at = $now;
                    $progress->save();
                }
            }

            return response()->json(['revision_id' => $revision->id, 'progress' => $this->progressData($progress)]);
        });
    }

    private function ensurePlayableRevision(Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): void
    {
        if ($revision->puzzle_id !== $puzzle->id
            || $puzzle->status !== 'published'
            || ! $settings->canPlayPuzzle($puzzle)
            || $revision->width < 1 || $revision->height < 1
            || $revision->width > 30 || $revision->height > 30) {
            throw new NotFoundHttpException;
        }
    }

    /** @return array<string, mixed> */
    private function progressData(PuzzleProgress $progress): array
    {
        return [
            'marks' => $progress->marks,
            'elapsed_seconds' => $progress->elapsed_seconds,
            'mistake_count' => $progress->mistake_count,
            'version' => $progress->version,
            'completed_at' => $progress->completed_at?->toIso8601String(),
            'last_played_at' => $progress->last_played_at?->toIso8601String(),
        ];
    }
}
