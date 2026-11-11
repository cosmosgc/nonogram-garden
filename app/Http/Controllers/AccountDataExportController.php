<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountDataExportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $puzzles = $user->puzzles()
            ->with('revisions')
            ->orderBy('id')
            ->get()
            ->map(fn ($puzzle) => [
                'id' => $puzzle->id,
                'title' => $puzzle->title,
                'description' => $puzzle->description,
                'tags' => $puzzle->tags ?? [],
                'status' => $puzzle->status,
                'visibility' => $puzzle->visibility,
                'moderation_status' => $puzzle->moderation_status,
                'moderation_note' => $puzzle->moderation_note,
                'published_at' => $puzzle->published_at,
                'width' => $puzzle->width,
                'height' => $puzzle->height,
                'draft_version' => $puzzle->draft_version,
                'draft_cells' => $puzzle->draft_cells,
                'created_at' => $puzzle->created_at,
                'updated_at' => $puzzle->updated_at,
                'revisions' => $puzzle->revisions->map(fn ($revision) => [
                    'revision_number' => $revision->revision_number,
                    'width' => $revision->width,
                    'height' => $revision->height,
                    'format_version' => $revision->format_version,
                    'solution_data' => $revision->solution_data,
                    'row_clues' => $revision->row_clues,
                    'column_clues' => $revision->column_clues,
                    'solution_hash' => $revision->solution_hash,
                    'validation_status' => $revision->validation_status,
                    'is_unique' => $revision->is_unique,
                    'created_at' => $revision->created_at,
                ])->values(),
            ])->values();
        $progress = $user->progressRecords()
            ->with(['revision:id,puzzle_id,revision_number,width,height', 'revision.puzzle:id,title'])
            ->orderBy('id')
            ->get()
            ->map(fn ($record) => [
                'puzzle_title' => $record->revision?->puzzle?->title,
                'revision_number' => $record->revision?->revision_number,
                'marks' => $record->marks,
                'elapsed_seconds' => $record->elapsed_seconds,
                'mistake_count' => $record->mistake_count,
                'version' => $record->version,
                'completed_at' => $record->completed_at,
                'last_played_at' => $record->last_played_at,
                'created_at' => $record->created_at,
                'updated_at' => $record->updated_at,
            ])->values();
        $collections = $user->collections()
            ->with('puzzles:id,title')
            ->orderBy('id')
            ->get()
            ->map(fn ($collection) => [
                'title' => $collection->title,
                'description' => $collection->description,
                'slug' => $collection->slug,
                'visibility' => $collection->visibility,
                'created_at' => $collection->created_at,
                'updated_at' => $collection->updated_at,
                'puzzles' => $collection->puzzles->map(fn ($puzzle) => [
                    'title' => $puzzle->title,
                    'position' => $puzzle->pivot->position,
                ])->values(),
            ])->values();

        $response = response()->json([
            'exported_at' => now()->toIso8601String(),
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'bio' => $user->bio,
                'avatar_path' => $user->avatar_path,
                'created_at' => $user->created_at,
            ],
            'puzzles' => $puzzles,
            'progress' => $progress,
            'collections' => $collections,
        ]);

        return $response
            ->header('Content-Disposition', 'attachment; filename="nonogram-account-export-'.$user->id.'.json"')
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
