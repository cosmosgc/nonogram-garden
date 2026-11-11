<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PuzzleModerationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Puzzle $puzzle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['username' => 'puzzle_owner']);
        $this->puzzle = Puzzle::factory()->create([
            'user_id' => $this->owner->id,
            'title' => 'Awaiting review',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->actingAs($this->owner)->postJson("/puzzles/drafts/{$this->puzzle->id}/publish", ['confirm' => true])
            ->assertCreated()->assertJsonPath('moderation_status', 'pending');
    }

    public function test_pending_puzzle_is_hidden_from_public_routes_but_owner_gets_a_solution_free_status_page(): void
    {
        $this->getJson(route('puzzles.show', $this->puzzle))->assertNotFound();
        $this->get('/puzzles')->assertOk()->assertDontSee('Awaiting review');
        $this->get('/@puzzle_owner')->assertOk()->assertDontSee('Awaiting review');
        $collection = $this->owner->collections()->create([
            'title' => 'Pending set', 'slug' => 'pending-set', 'visibility' => 'public',
        ]);
        $collection->puzzles()->attach($this->puzzle->id, ['position' => 1]);
        $this->get(route('collections.show', $collection))->assertOk()->assertDontSee('Awaiting review');
        $this->actingAs($this->owner)->get(route('puzzles.show', $this->puzzle))
            ->assertOk()->assertSee('waiting for moderator approval')->assertDontSee('solution_data')->assertDontSee('draft_cells');

        $player = User::factory()->create();
        $revision = $this->puzzle->fresh()->publishedRevision;
        $this->actingAs($player)->getJson("/puzzles/{$this->puzzle->id}/revisions/{$revision->id}/progress")->assertNotFound();
    }

    public function test_only_moderators_can_approve_and_approval_unlocks_public_access(): void
    {
        $this->post(route('moderation.update', $this->puzzle), ['action' => 'approve'])->assertForbidden();

        $moderator = User::factory()->create(['is_moderator' => true]);
        $this->actingAs($moderator)->get(route('moderation.index'))->assertOk()->assertSee('Awaiting review');
        $this->post(route('moderation.update', $this->puzzle), ['action' => 'approve', 'note' => 'Reviewed'])
            ->assertRedirect(route('moderation.index'));

        $this->assertDatabaseHas('puzzles', [
            'id' => $this->puzzle->id,
            'moderation_status' => 'approved',
            'moderated_by' => $moderator->id,
            'moderation_note' => 'Reviewed',
        ]);
        $this->getJson(route('puzzles.show', $this->puzzle))->assertOk()->assertJsonPath('row_clues', [[1]])
            ->assertJsonMissingPath('solution_data');
    }

    public function test_moderation_queue_renders_color_clues_with_palette_names(): void
    {
        $palette = [
            ['name' => 'Crimson', 'hex' => '#C0392B'],
            ['name' => 'Ocean', 'hex' => '#2471A3'],
        ];
        $colorPuzzle = Puzzle::factory()->create([
            'user_id' => $this->owner->id,
            'title' => 'Color review',
            'width' => 2,
            'height' => 1,
            'draft_cells' => '12',
            'draft_mode' => 'color',
            'draft_rules_version' => 1,
            'draft_palette' => $palette,
        ]);
        $this->actingAs($this->owner)->postJson("/puzzles/drafts/{$colorPuzzle->id}/publish", ['confirm' => true])->assertCreated();

        $moderator = User::factory()->create(['is_moderator' => true]);
        $this->actingAs($moderator)->get(route('moderation.index'))
            ->assertOk()
            ->assertSee('Color Picross')
            ->assertSee('1 Crimson')
            ->assertSee('1 Ocean')
            ->assertDontSee('Array Array');
    }

    public function test_moderator_role_can_be_granted_and_revoked_from_the_cli(): void
    {
        $user = User::factory()->create();

        $this->artisan('nonogram:moderator', ['email' => $user->email])->assertExitCode(0);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_moderator' => true]);

        $this->artisan('nonogram:moderator', ['email' => $user->email, '--revoke' => true])->assertExitCode(0);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_moderator' => false]);
    }

    public function test_rejected_puzzle_requires_a_note_and_remains_hidden_with_feedback_for_its_owner(): void
    {
        $moderator = User::factory()->create(['is_moderator' => true]);
        $this->actingAs($moderator)->from(route('moderation.index'))
            ->post(route('moderation.update', $this->puzzle), ['action' => 'reject'])
            ->assertSessionHasErrors('note');

        $this->post(route('moderation.update', $this->puzzle), [
            'action' => 'reject',
            'note' => 'Please revise the title.',
        ])->assertRedirect(route('moderation.index'));

        $this->getJson(route('puzzles.show', $this->puzzle))->assertNotFound();
        $this->actingAs($this->owner)->get(route('puzzles.show', $this->puzzle))
            ->assertOk()->assertSee('Please revise the title.');
    }
}
