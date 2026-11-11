<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use App\Services\Nonogram\PuzzleStartHintService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PublicPuzzleController extends Controller
{
    public function show(Request $request, Puzzle $puzzle, DiscoverySettings $settings, PuzzleStartHintService $hints): JsonResponse|View
    {
        $puzzle->load(['owner', 'publishedRevision']);

        return $this->respond($request, $puzzle, $puzzle->publishedRevision, $settings, $hints);
    }

    public function showRevision(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings, PuzzleStartHintService $hints): JsonResponse|View
    {
        return $this->respond($request, $puzzle, $revision, $settings, $hints);
    }

    private function respond(Request $request, Puzzle $puzzle, ?PuzzleRevision $revision, DiscoverySettings $settings, PuzzleStartHintService $hints): JsonResponse|View
    {
        if ($revision === null || $revision->puzzle_id !== $puzzle->id
            || $puzzle->status !== 'published' || ! in_array($puzzle->visibility, ['public', 'unlisted'], true)) {
            throw new NotFoundHttpException;
        }

        $puzzle->loadMissing('owner');
        if (! $settings->canPlayPuzzle($puzzle)) {
            if ($request->user()?->id === $puzzle->user_id && ! $request->expectsJson()) {
                return view('puzzles.pending', ['puzzle' => $puzzle]);
            }
            throw new NotFoundHttpException;
        }
        $startHint = $hints->forRevision($request, $revision);
        $data = [
            'id' => $puzzle->id,
            'title' => $puzzle->title,
            'description' => $puzzle->description,
            'moderation_status' => $puzzle->moderation_status,
            'start_hint' => $startHint,
            'tags' => $puzzle->tags ?? [],
            'width' => $revision->width,
            'height' => $revision->height,
            'author' => ['name' => $puzzle->owner->name, 'username' => $puzzle->owner->username],
            'revision_id' => $revision->id,
            'mode' => $revision->mode,
            'rules_version' => $revision->rules_version,
            'palette' => $revision->palette,
            'row_clues' => $revision->row_clues,
            'column_clues' => $revision->column_clues,
            'published_at' => $puzzle->published_at?->toIso8601String(),
        ];

        return $request->expectsJson()
            ? response()->json($data)
            : view('puzzles.show', ['puzzle' => $puzzle, 'revision' => $revision, 'startHint' => $startHint]);
    }
}
