<?php

namespace App\Policies;

use App\Models\Puzzle;
use App\Models\User;

class PuzzlePolicy
{
    public function view(User $user, Puzzle $puzzle): bool
    {
        return $puzzle->user_id === $user->id;
    }

    public function update(User $user, Puzzle $puzzle): bool
    {
        return $puzzle->user_id === $user->id && $puzzle->status === 'draft';
    }

    public function publish(User $user, Puzzle $puzzle): bool
    {
        return $puzzle->user_id === $user->id && $puzzle->status === 'draft';
    }

    public function unpublish(User $user, Puzzle $puzzle): bool
    {
        return $puzzle->user_id === $user->id && $puzzle->status === 'published';
    }
}
