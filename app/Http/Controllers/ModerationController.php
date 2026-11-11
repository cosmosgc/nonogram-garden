<?php

namespace App\Http\Controllers;

use App\Http\Requests\ModeratePuzzleRequest;
use App\Http\Requests\UpdateDiscoverySettingsRequest;
use App\Models\Puzzle;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModerationController extends Controller
{
    public function index(Request $request, DiscoverySettings $settings): View
    {
        abort_unless($request->user()?->is_moderator || $request->user()?->is_admin, 403);
        $puzzles = Puzzle::query()
            ->where('status', 'published')
            ->whereIn('moderation_status', ['pending', 'rejected'])
            ->with(['owner:id,name,username', 'publishedRevision:id,puzzle_id,width,height,mode,palette,row_clues,column_clues,validation_status,is_unique'])
            ->orderBy('updated_at')
            ->paginate(20);

        return view('moderation.index', [
            'puzzles' => $puzzles,
            'isAdmin' => (bool) $request->user()->is_admin,
            'showPendingPublicPuzzles' => $settings->showPendingPublicPuzzles(),
            'formatClue' => static function (PuzzleRevision $revision, array $line): string {
                if ($revision->mode !== 'color') {
                    return implode(' ', $line);
                }

                if ($line === []) {
                    return 'Empty line';
                }

                $palette = $revision->palette ?? [];

                return implode(', ', array_map(static function (array $run) use ($palette): string {
                    $colorId = $run[1];

                    return $run[0].' '.($palette[$colorId - 1]['name'] ?? "Colour {$colorId}");
                }, $line));
            },
        ]);
    }

    public function updateSettings(UpdateDiscoverySettingsRequest $request, DiscoverySettings $settings): RedirectResponse
    {
        $settings->setShowPendingPublicPuzzles((bool) $request->validated('show_pending_public_puzzles'), $request->user()->id);

        return redirect()->route('moderation.index')->with('status', 'Site-wide discovery setting updated.');
    }

    public function update(ModeratePuzzleRequest $request, Puzzle $puzzle): RedirectResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($request, $puzzle, $data): void {
            $locked = Puzzle::query()->lockForUpdate()->findOrFail($puzzle->id);
            abort_unless($request->user()->is_moderator || $request->user()->is_admin, 403);
            if ($locked->status !== 'published' || $locked->published_revision_id === null) {
                throw ValidationException::withMessages(['puzzle' => 'Only published puzzles can be moderated.']);
            }

            if ($data['action'] === 'approve') {
                $revision = $locked->publishedRevision()->firstOrFail();
                if (! $revision->is_unique || $revision->validation_status !== 'unique') {
                    throw ValidationException::withMessages(['puzzle' => 'Only a server-verified unique revision can be approved.']);
                }
            }

            $locked->forceFill([
                'moderation_status' => match ($data['action']) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    'remove' => 'removed',
                },
                'moderation_note' => $data['note'] ?? null,
                'moderated_by' => $request->user()->id,
                'moderated_at' => now(),
            ])->save();
        });

        return redirect()->route('moderation.index')->with('status', 'Moderation decision saved.');
    }
}
