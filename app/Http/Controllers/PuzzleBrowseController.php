<?php

namespace App\Http\Controllers;

use App\Http\Requests\BrowsePuzzlesRequest;
use App\Models\Puzzle;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Contracts\View\View;

class PuzzleBrowseController extends Controller
{
    public function __invoke(BrowsePuzzlesRequest $request, DiscoverySettings $settings): View
    {
        $filters = $request->validated();
        $query = Puzzle::query()
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereIn('moderation_status', $settings->visibleModerationStatuses())
            ->with([
                'owner:id,name,username,avatar_path',
                'publishedRevision:id,puzzle_id,width,height',
            ]);

        if (! empty($filters['width'])) {
            $query->whereHas('publishedRevision', fn ($revisionQuery) => $revisionQuery->where('width', $filters['width']));
        }
        if (! empty($filters['height'])) {
            $query->whereHas('publishedRevision', fn ($revisionQuery) => $revisionQuery->where('height', $filters['height']));
        }
        if (! empty($filters['tag'])) {
            $query->whereJsonContains('tags', $filters['tag']);
        }
        if (! empty($filters['creator'])) {
            $username = mb_strtolower($filters['creator']);
            $query->whereHas('owner', fn ($ownerQuery) => $ownerQuery->where('username', $username));
        }

        $query->orderBy('published_at', ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');
        $puzzles = $query->paginate(12)->withQueryString();

        $completedSolutions = collect();
        if ($request->user()) {
            $completedRevisionIds = $request->user()->progressRecords()
                ->whereNotNull('completed_at')
                ->whereIn('puzzle_revision_id', $puzzles->getCollection()->pluck('published_revision_id'))
                ->pluck('puzzle_revision_id');

            $completedSolutions = PuzzleRevision::query()
                ->whereIn('id', $completedRevisionIds)
                ->get(['id', 'solution_data', 'width', 'height'])
                ->keyBy('id');
        }

        return view('puzzles.index', ['puzzles' => $puzzles, 'filters' => $filters, 'completedSolutions' => $completedSolutions]);
    }
}
