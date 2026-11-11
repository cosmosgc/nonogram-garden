<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PuzzleStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_sees_idempotent_registered_starts_and_server_verified_completions_only(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Owner statistics',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        $progressUrl = "/puzzles/{$puzzle->id}/revisions/{$revision->id}/progress";

        $solver = User::factory()->create();
        $this->actingAs($solver)->putJson($progressUrl, [
            'marks' => '0', 'elapsed_seconds' => 5, 'version' => 0,
        ])->assertOk();
        $this->putJson($progressUrl, [
            'marks' => '1', 'elapsed_seconds' => 9, 'version' => 1,
        ])->assertOk();
        $this->postJson("/puzzles/{$puzzle->id}/revisions/{$revision->id}/complete")->assertOk();

        $secondPlayer = User::factory()->create();
        $this->actingAs($secondPlayer)->putJson($progressUrl, [
            'marks' => '0', 'elapsed_seconds' => 3, 'version' => 0,
        ])->assertOk();

        $this->actingAs($owner)->get('/dashboard')->assertOk()
            ->assertSee('Owner statistics')
            ->assertSee('Registered starts: 2 · verified completions: 1');
        $this->actingAs($secondPlayer)->get('/dashboard')->assertOk()
            ->assertDontSee('Registered starts: 2');
        $this->getJson(route('puzzles.show', $puzzle))->assertOk()->assertJsonMissingPath('stats');
    }
}
