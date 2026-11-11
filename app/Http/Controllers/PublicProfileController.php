<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Contracts\View\View;

class PublicProfileController extends Controller
{
    public function __invoke(string $username, DiscoverySettings $settings): View
    {
        $user = User::query()->where('username', mb_strtolower($username))->firstOrFail();
        $puzzles = $user->puzzles()
            ->where('status', 'published')
            ->where('visibility', 'public')
            ->whereIn('moderation_status', $settings->visibleModerationStatuses())
            ->with('publishedRevision:id,puzzle_id,width,height')
            ->orderByDesc('published_at')
            ->paginate(12, ['*'], 'puzzles_page');
        $collections = $user->collections()
            ->where('visibility', 'public')
            ->orderByDesc('updated_at')
            ->paginate(12, ['*'], 'collections_page');

        return view('profiles.show', ['profile' => $user, 'puzzles' => $puzzles, 'collections' => $collections]);
    }
}
