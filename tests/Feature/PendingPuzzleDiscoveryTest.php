<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingPuzzleDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPuzzle(User $owner, string $title = 'Pending public puzzle'): Puzzle
    {
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => $title,
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);

        $this->actingAs($owner)
            ->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])
            ->assertCreated();

        return $puzzle->fresh();
    }

    private function setPendingDiscovery(User $admin, bool $enabled): void
    {
        $this->actingAs($admin)
            ->patch(route('moderation.settings.update'), ['show_pending_public_puzzles' => $enabled ? '1' : '0'])
            ->assertRedirect(route('moderation.index'));
    }

    public function test_only_administrators_can_change_the_site_wide_pending_visibility_toggle(): void
    {
        $moderator = User::factory()->create(['is_moderator' => true]);
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($moderator)->get(route('moderation.index'))
            ->assertOk()
            ->assertDontSee('Show pending public puzzles site-wide');
        $this->patch(route('moderation.settings.update'), ['show_pending_public_puzzles' => '1'])->assertForbidden();

        $this->actingAs($admin)->get(route('moderation.index'))
            ->assertOk()
            ->assertSee('Show pending public puzzles site-wide');
        $this->patch(route('moderation.settings.update'), ['show_pending_public_puzzles' => '1'])
            ->assertRedirect(route('moderation.index'));
        $this->get(route('moderation.index'))->assertSee('checked', false);
        $this->assertDatabaseHas('site_settings', ['key' => 'show_pending_public_puzzles', 'value' => 1]);

        $this->patch(route('moderation.settings.update'), ['show_pending_public_puzzles' => '0'])
            ->assertRedirect(route('moderation.index'));
        $this->assertDatabaseHas('site_settings', ['key' => 'show_pending_public_puzzles', 'value' => 0]);
    }

    public function test_enabled_toggle_exposes_pending_public_puzzles_across_discovery_and_verified_play(): void
    {
        $owner = User::factory()->create(['username' => 'pending_artist']);
        $admin = User::factory()->create(['is_admin' => true]);
        $puzzle = $this->pendingPuzzle($owner);
        $collection = $owner->collections()->create([
            'title' => 'Pending showcase',
            'slug' => 'pending-showcase',
            'visibility' => 'public',
        ]);

        $this->setPendingDiscovery($admin, true);
        $this->actingAs($owner)->put(route('collections.puzzles.sync', $collection), [
            'positions' => [$puzzle->id => 1],
        ])->assertRedirect();

        $this->get('/puzzles')->assertOk()->assertSee('Pending public puzzle')->assertSee('Pending review');
        $this->get('/@pending_artist')->assertOk()->assertSee('Pending public puzzle')->assertSee('Pending review');
        $this->get(route('collections.show', $collection))->assertOk()->assertSee('Pending public puzzle')->assertSee('Pending review');
        $this->getJson(route('puzzles.show', $puzzle))
            ->assertOk()
            ->assertJsonPath('moderation_status', 'pending')
            ->assertJsonMissingPath('solution_data');
        $this->get(route('puzzles.show', $puzzle))->assertOk()->assertSee('currently visible under the site-wide preview setting');

        $player = User::factory()->create();
        $progressUrl = route('puzzles.progress.save', ['puzzle' => $puzzle->id, 'revision' => $puzzle->published_revision_id]);
        $this->actingAs($player)->putJson($progressUrl, [
            'marks' => '1', 'elapsed_seconds' => 1, 'version' => 0,
        ])->assertOk();
        $this->postJson(route('puzzles.progress.complete', ['puzzle' => $puzzle->id, 'revision' => $puzzle->published_revision_id]))
            ->assertOk()->assertJsonPath('completed', true);

        $this->setPendingDiscovery($admin, false);
        $this->get('/puzzles')->assertOk()->assertDontSee('Pending public puzzle');
        $this->getJson(route('puzzles.show', $puzzle))->assertNotFound();
        $this->getJson(route('puzzles.progress.show', ['puzzle' => $puzzle->id, 'revision' => $puzzle->published_revision_id]))->assertNotFound();
    }

    public function test_toggle_never_exposes_unlisted_rejected_or_removed_puzzles(): void
    {
        $owner = User::factory()->create(['username' => 'hidden_artist']);
        $admin = User::factory()->create(['is_admin' => true]);
        $unlistedPending = $this->pendingPuzzle($owner, 'Unlisted pending puzzle');
        $unlistedPending->update(['visibility' => 'unlisted']);
        $rejected = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Rejected puzzle',
            'status' => 'published',
            'visibility' => 'public',
            'moderation_status' => 'rejected',
        ]);
        $removed = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Removed puzzle',
            'status' => 'published',
            'visibility' => 'public',
            'moderation_status' => 'removed',
        ]);

        $this->setPendingDiscovery($admin, true);
        $this->get('/puzzles')
            ->assertOk()
            ->assertDontSee('Unlisted pending puzzle')
            ->assertDontSee('Rejected puzzle')
            ->assertDontSee('Removed puzzle');
        $this->getJson(route('puzzles.show', $unlistedPending))->assertNotFound();
        $this->getJson(route('puzzles.show', $rejected))->assertNotFound();
        $this->getJson(route('puzzles.show', $removed))->assertNotFound();
    }
}
