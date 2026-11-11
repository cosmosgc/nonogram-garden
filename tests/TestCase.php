<?php

namespace Tests;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    protected function publishAndApprove(Puzzle $puzzle, User $owner): TestResponse
    {
        $response = $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true]);
        if ($response->status() === 201) {
            $this->approveForTesting($puzzle);
        }

        return $response;
    }

    protected function approveForTesting(Puzzle $puzzle): void
    {
        $moderator = User::factory()->create(['is_moderator' => true]);
        $owner = User::query()->findOrFail($puzzle->user_id);
        $this->actingAs($moderator)->post(route('moderation.update', $puzzle), ['action' => 'approve'])
            ->assertRedirect(route('moderation.index'));
        $this->actingAs($owner);
    }
}
