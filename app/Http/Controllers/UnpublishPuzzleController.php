<?php

namespace App\Http\Controllers;

use App\Http\Requests\UnpublishPuzzleRequest;
use App\Models\Puzzle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UnpublishPuzzleController extends Controller
{
    public function __invoke(UnpublishPuzzleRequest $request, Puzzle $puzzle): JsonResponse|RedirectResponse
    {
        $draftId = DB::transaction(function () use ($puzzle): int {
            $locked = Puzzle::query()->lockForUpdate()->findOrFail($puzzle->getKey());
            Gate::authorize('unpublish', $locked);
            $revision = $locked->publishedRevision()->firstOrFail();

            $locked->fill([
                'status' => 'draft',
                'visibility' => 'private',
                'width' => $revision->width,
                'height' => $revision->height,
                'draft_cells' => $revision->solution_data,
                'draft_original_image_path' => $revision->original_image_path,
                'draft_reveal_image_path' => $revision->reveal_image_path,
                'draft_version' => $locked->draft_version + 1,
                'published_at' => null,
            ])->save();

            return $locked->id;
        });

        if ($request->expectsJson()) {
            return response()->json(['id' => $draftId, 'status' => 'draft'], 200);
        }

        return redirect()->route('editor.edit', ['puzzle' => $draftId]);
    }
}
