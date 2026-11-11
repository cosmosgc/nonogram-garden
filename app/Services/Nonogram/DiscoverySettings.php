<?php

namespace App\Services\Nonogram;

use App\Models\Puzzle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DiscoverySettings
{
    public const SHOW_PENDING_CACHE_KEY = 'nonogram.discovery.show_pending_public_puzzles';

    public function showPendingPublicPuzzles(): bool
    {
        return Cache::rememberForever(self::SHOW_PENDING_CACHE_KEY, fn (): bool => (bool) DB::table('site_settings')
            ->where('key', 'show_pending_public_puzzles')
            ->value('value'));
    }

    public function visibleModerationStatuses(): array
    {
        return $this->showPendingPublicPuzzles() ? ['approved', 'pending'] : ['approved'];
    }

    public function canDiscoverPuzzle(Puzzle $puzzle): bool
    {
        if ($puzzle->status !== 'published' || $puzzle->visibility !== 'public') {
            return false;
        }

        return $puzzle->moderation_status === 'approved'
            || ($puzzle->moderation_status === 'pending' && $this->showPendingPublicPuzzles());
    }

    public function canPlayPuzzle(Puzzle $puzzle): bool
    {
        if ($puzzle->status !== 'published' || ! in_array($puzzle->visibility, ['public', 'unlisted'], true)) {
            return false;
        }

        return $puzzle->moderation_status === 'approved'
            || ($puzzle->moderation_status === 'pending'
                && $puzzle->visibility === 'public'
                && $this->showPendingPublicPuzzles());
    }

    public function setShowPendingPublicPuzzles(bool $show, int $adminId): void
    {
        $now = now();
        DB::table('site_settings')->updateOrInsert(
            ['key' => 'show_pending_public_puzzles'],
            ['value' => $show, 'updated_by' => $adminId, 'created_at' => $now, 'updated_at' => $now],
        );
        Cache::forever(self::SHOW_PENDING_CACHE_KEY, $show);
    }
}
