<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\PuzzleProgress;
use App\Models\PuzzleRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PuzzleProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Puzzle $puzzle;

    private PuzzleRevision $revision;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->puzzle = Puzzle::factory()->create([
            'user_id' => $this->owner->id,
            'width' => 2,
            'height' => 2,
            'draft_cells' => '1000',
        ]);
        $this->publishAndApprove($this->puzzle, $this->owner)->assertCreated();
        $this->revision = $this->puzzle->fresh()->publishedRevision;
    }

    public function test_signed_in_player_can_save_and_resume_progress_for_a_revision(): void
    {
        $player = User::factory()->create();
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";
        $this->actingAs($player)->getJson($url)
            ->assertOk()
            ->assertJsonPath('revision_id', $this->revision->id)
            ->assertJsonPath('progress.marks', '0000')
            ->assertJsonPath('progress.version', 0)
            ->assertJsonMissingPath('solution_data');

        $saved = $this->putJson($url, [
            'marks' => '1200',
            'elapsed_seconds' => 73,
            'version' => 0,
        ])->assertOk()
            ->assertJsonPath('progress.marks', '1200')
            ->assertJsonPath('progress.elapsed_seconds', 73)
            ->assertJsonPath('progress.version', 1);
        $this->assertNotNull($saved->json('progress.completed_at'));

        $this->getJson($url)
            ->assertOk()
            ->assertJsonPath('progress.marks', '1200')
            ->assertJsonPath('progress.mistake_count', 0)
            ->assertJsonPath('progress.version', 1);
        $this->assertDatabaseHas('puzzle_progress', [
            'user_id' => $player->id,
            'puzzle_revision_id' => $this->revision->id,
        ]);
        $this->assertNotNull(PuzzleProgress::query()->where('user_id', $player->id)
            ->where('puzzle_revision_id', $this->revision->id)->value('completed_at'));
    }

    public function test_guest_player_page_uses_local_progress_and_authenticated_player_gets_no_trusted_user_input(): void
    {
        auth()->logout();
        $this->get("/puzzles/{$this->puzzle->id}")
            ->assertOk()
            ->assertSee('data-puzzle-id="'.$this->puzzle->id.'"', false)
            ->assertSee('data-revision-id="'.$this->revision->id.'"', false)
            ->assertDontSee('data-user-id=', false)
            ->assertDontSee('data-solution', false);

        $player = User::factory()->create();
        $this->actingAs($player)->get("/puzzles/{$this->puzzle->id}")
            ->assertOk()
            ->assertSee('data-user-id="'.$player->id.'"', false)
            ->assertDontSee('data-solution', false);
    }

    public function test_progress_is_isolated_between_users(): void
    {
        $firstPlayer = User::factory()->create();
        $secondPlayer = User::factory()->create();
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";

        $this->actingAs($firstPlayer)->putJson($url, ['marks' => '1000', 'elapsed_seconds' => 12, 'version' => 0])->assertOk();
        $this->actingAs($secondPlayer)->getJson($url)->assertOk()->assertJsonPath('progress.marks', '0000');
        $this->assertDatabaseCount('puzzle_progress', 1);
    }

    public function test_dashboard_resumes_the_exact_revision_for_saved_games(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player)->putJson("/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress", [
            'marks' => '1000',
            'elapsed_seconds' => 37,
            'version' => 0,
        ])->assertOk();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Your recent games')
            ->assertSee('Completed')
            ->assertSee('View revision')
            ->assertSee(route('puzzles.revisions.show', ['puzzle' => $this->puzzle->id, 'revision' => $this->revision->id]));
    }

    public function test_stale_version_does_not_create_an_empty_progress_slot(): void
    {
        $player = User::factory()->create();
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";

        $this->actingAs($player)->putJson($url, ['marks' => '1000', 'elapsed_seconds' => 8, 'version' => 4])
            ->assertStatus(409)->assertJsonPath('current_version', 0);
        $this->assertDatabaseCount('puzzle_progress', 0);
    }

    public function test_stale_progress_versions_conflict_without_overwriting_saved_marks(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player);
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";
        $this->putJson($url, ['marks' => '1000', 'elapsed_seconds' => 3, 'version' => 0])->assertOk();

        $this->putJson($url, ['marks' => '2222', 'elapsed_seconds' => 30, 'version' => 0])
            ->assertStatus(409)->assertJsonPath('current_version', 1);
        $this->assertDatabaseHas('puzzle_progress', [
            'user_id' => $player->id,
            'puzzle_revision_id' => $this->revision->id,
            'marks' => '1000',
            'version' => 1,
        ]);
    }

    public function test_saving_the_exact_solution_auto_completes_and_explicit_completion_remains_idempotent(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player);
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}";
        $saved = $this->putJson("{$url}/progress", ['marks' => '1000', 'elapsed_seconds' => 12, 'version' => 0])
            ->assertOk()->assertJsonPath('progress.marks', '1000');
        $this->assertNotNull($saved->json('progress.completed_at'));

        $first = $this->postJson("{$url}/complete", ['completed' => false, 'solution' => '0000'])
            ->assertOk()->assertJsonPath('completed', true)->assertJsonPath('already_completed', true);
        $completedAt = $first->json('completed_at');
        $this->postJson("{$url}/complete")
            ->assertOk()->assertJsonPath('completed', true)->assertJsonPath('already_completed', true)
            ->assertJsonPath('completed_at', $completedAt)->assertJsonMissingPath('solution_data');
        $this->putJson("{$url}/progress", ['marks' => '0000', 'elapsed_seconds' => 20, 'version' => 1])->assertStatus(409);
        $this->assertNotNull(PuzzleProgress::query()->where('user_id', $player->id)
            ->where('puzzle_revision_id', $this->revision->id)->value('completed_at'));
    }

    public function test_wrong_saved_marks_and_forged_completion_are_rejected_without_leaking_solution(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player);
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}";

        $this->postJson("{$url}/complete", ['completed' => true])->assertUnprocessable();
        $this->putJson("{$url}/progress", ['marks' => '0100', 'elapsed_seconds' => 20, 'version' => 0])
            ->assertOk()->assertJsonPath('progress.completed_at', null);
        $this->postJson("{$url}/complete", ['completed' => true])->assertUnprocessable()
            ->assertJsonPath('completed', false)->assertJsonMissingPath('solution_data');
        $this->assertDatabaseHas('puzzle_progress', [
            'user_id' => $player->id,
            'completed_at' => null,
            'mistake_count' => 1,
            'version' => 2,
        ]);
    }

    public function test_invalid_marks_and_timer_values_are_rejected(): void
    {
        $player = User::factory()->create();
        $this->actingAs($player);
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";

        $this->putJson($url, ['marks' => '10X0', 'elapsed_seconds' => 1, 'version' => 0])->assertUnprocessable();
        $this->putJson($url, ['marks' => '100', 'elapsed_seconds' => 1, 'version' => 0])->assertUnprocessable();
        $this->putJson($url, ['marks' => '0000', 'elapsed_seconds' => -1, 'version' => 0])->assertUnprocessable();
        $this->putJson($url, ['marks' => '0000', 'elapsed_seconds' => 1, 'version' => 0, 'mistake_count' => -1])->assertUnprocessable();
        $this->putJson($url, ['marks' => '0000', 'elapsed_seconds' => 1, 'version' => 0, 'user_id' => $this->owner->id])->assertUnprocessable();
        $this->assertDatabaseCount('puzzle_progress', 0);
    }

    public function test_color_progress_accepts_palette_ids_and_crosses_but_rejects_unknown_palette_ids(): void
    {
        DB::table('puzzle_revisions')->where('id', $this->revision->id)->update([
            'mode' => 'color',
            'palette' => json_encode([
                ['name' => 'Crimson', 'hex' => '#C0392B'],
                ['name' => 'Ocean', 'hex' => '#2471A3'],
            ]),
        ]);
        $player = User::factory()->create();
        $this->actingAs($player);
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";

        $this->putJson($url, ['marks' => '2900', 'elapsed_seconds' => 8, 'version' => 0])
            ->assertOk()->assertJsonPath('progress.marks', '2900')
            ->assertJsonPath('progress.completed_at', null);
        $this->putJson($url, ['marks' => '3900', 'elapsed_seconds' => 9, 'version' => 1])
            ->assertUnprocessable();

        $this->assertDatabaseHas('puzzle_progress', [
            'user_id' => $player->id,
            'puzzle_revision_id' => $this->revision->id,
            'marks' => '2900',
            'version' => 1,
        ]);
    }

    public function test_authenticated_color_progress_completes_only_on_exact_palette_ids_and_resumes_without_solution(): void
    {
        $palette = [
            ['name' => 'Crimson', 'hex' => '#C0392B'],
            ['name' => 'Ocean', 'hex' => '#2471A3'],
        ];
        $puzzle = Puzzle::factory()->create([
            'user_id' => $this->owner->id,
            'width' => 3,
            'height' => 1,
            'draft_cells' => '120',
            'draft_mode' => 'color',
            'draft_rules_version' => 1,
            'draft_palette' => $palette,
        ]);
        $this->publishAndApprove($puzzle, $this->owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        $player = User::factory()->create();
        $this->actingAs($player);
        $url = "/puzzles/{$puzzle->id}/revisions/{$revision->id}/progress";

        $this->getJson($url)->assertOk()
            ->assertJsonPath('progress.marks', '000')
            ->assertJsonMissingPath('solution_data');
        $this->putJson($url, ['marks' => '119', 'elapsed_seconds' => 12, 'version' => 0])
            ->assertOk()->assertJsonPath('progress.completed_at', null);
        $completed = $this->putJson($url, ['marks' => '129', 'elapsed_seconds' => 18, 'version' => 1])
            ->assertOk()->assertJsonPath('progress.marks', '129')
            ->assertJsonPath('progress.version', 2);
        $this->assertNotNull($completed->json('progress.completed_at'));
        $this->getJson($url)->assertOk()
            ->assertJsonPath('progress.marks', '129')
            ->assertJsonMissingPath('solution_data');
        $this->putJson($url, ['marks' => '000', 'elapsed_seconds' => 20, 'version' => 2])->assertConflict();
    }

    public function test_progress_requires_authentication_and_only_published_matching_revisions_are_playable(): void
    {
        $url = "/puzzles/{$this->puzzle->id}/revisions/{$this->revision->id}/progress";
        auth()->logout();
        $this->getJson($url)->assertUnauthorized();

        $otherPuzzle = Puzzle::factory()->create([
            'user_id' => $this->owner->id,
            'width' => 2,
            'height' => 2,
            'draft_cells' => '1000',
        ]);
        $this->publishAndApprove($otherPuzzle, $this->owner)->assertCreated();
        $otherRevision = $otherPuzzle->fresh()->publishedRevision;
        $this->getJson("/puzzles/{$this->puzzle->id}/revisions/{$otherRevision->id}/progress")->assertNotFound();
        $this->postJson("/puzzles/{$this->puzzle->id}/revisions/{$otherRevision->id}/complete")->assertNotFound();

        $draft = Puzzle::factory()->create(['user_id' => $this->owner->id]);
        $this->getJson("/puzzles/{$draft->id}/revisions/{$otherRevision->id}/progress")->assertNotFound();
    }
}
