<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PuzzleGameSessionTest extends TestCase
{
    use RefreshDatabase;

    private function publish(User $owner, int $width, int $height, string $cells): Puzzle
    {
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => $width,
            'height' => $height,
            'draft_cells' => $cells,
        ]);
        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])->assertCreated();
        $puzzle->refresh();
        $puzzle->forceFill(['moderation_status' => 'approved'])->save();

        return $puzzle->fresh();
    }

    private function fill(string $url, int $cellIndex, ?int $mark = null): TestResponse
    {
        return $this->postJson($url, array_filter([
            'cell_index' => $cellIndex,
            'guess_id' => (string) Str::uuid(),
            'mark' => $mark,
        ], fn (mixed $value): bool => $value !== null));
    }

    public function test_normal_mode_initializes_size_based_lives_and_wrong_fills_cost_a_life(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 2, 2, '1010');
        $revision = $puzzle->publishedRevision;
        $base = "/puzzles/{$puzzle->id}/revisions/{$revision->id}";

        $this->getJson("{$base}/game-session")
            ->assertOk()
            ->assertJsonPath('hardcore', false)
            ->assertJsonPath('lives_remaining', 3)
            ->assertJsonPath('initial_lives', 3);

        $this->fill("{$base}/life-check", 0)->assertOk()->assertJsonPath('lives_remaining', 3);
        $this->fill("{$base}/life-check", 1)
            ->assertOk()
            ->assertJsonPath('lives_remaining', 2)
            ->assertJsonPath('mode_locked', true)
            ->assertJsonMissingPath('is_correct')
            ->assertJsonMissingPath('solution_data');
        $this->fill("{$base}/life-check", 3)->assertOk()->assertJsonPath('lives_remaining', 1);
        $this->fill("{$base}/life-check", 1)->assertOk()->assertJsonPath('lives_remaining', 0)->assertJsonPath('game_over', true);
        $this->fill("{$base}/life-check", 0)->assertConflict();
        $this->postJson("{$base}/restart", [])->assertOk()->assertJsonPath('lives_remaining', 3)->assertJsonPath('game_over', false);
    }

    public function test_correct_fills_do_not_cost_lives_and_hardcore_locks_after_a_fill_without_lives(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 2, 2, '1010');
        $revision = $puzzle->publishedRevision;
        $base = "/puzzles/{$puzzle->id}/revisions/{$revision->id}";

        $this->postJson("{$base}/game-mode", ['hardcore' => true])
            ->assertOk()->assertJsonPath('hardcore', true)->assertJsonPath('lives_remaining', null);
        $this->fill("{$base}/life-check", 0)
            ->assertOk()->assertJsonPath('hardcore', true)->assertJsonPath('lives_remaining', null)->assertJsonPath('mode_locked', true);
        $this->postJson("{$base}/game-mode", ['hardcore' => false])->assertConflict();
        $this->postJson("{$base}/restart", [])->assertStatus(409);
    }

    public function test_replayed_guess_requests_are_idempotent(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 2, 2, '1010');
        $revision = $puzzle->publishedRevision;
        $url = "/puzzles/{$puzzle->id}/revisions/{$revision->id}/life-check";
        $guessId = (string) Str::uuid();

        $this->postJson($url, ['cell_index' => 1, 'guess_id' => $guessId])->assertOk()->assertJsonPath('lives_remaining', 2);
        $this->postJson($url, ['cell_index' => 1, 'guess_id' => $guessId])->assertOk()->assertJsonPath('lives_remaining', 2);
    }

    public function test_game_session_and_life_check_follow_puzzle_visibility_rules(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 2, 2, '1111');
        $puzzle->update(['visibility' => 'private']);
        $revision = $puzzle->publishedRevision;
        $base = "/puzzles/{$puzzle->id}/revisions/{$revision->id}";

        $this->getJson("{$base}/game-session")->assertNotFound();
        $this->postJson("{$base}/life-check", ['cell_index' => 0, 'guess_id' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_life_check_rejects_cell_indices_outside_the_revision(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 2, 2, '1111');
        $revision = $puzzle->publishedRevision;
        $base = "/puzzles/{$puzzle->id}/revisions/{$revision->id}/life-check";

        $this->postJson($base, ['cell_index' => 4, 'guess_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson($base, ['cell_index' => -1, 'guess_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_color_fill_checks_exact_palette_id_without_exposing_solution(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 2, 2, '1000');
        $revision = $puzzle->publishedRevision;
        DB::table('puzzle_revisions')->where('id', $revision->id)->update([
            'mode' => 'color',
            'palette' => json_encode([
                ['name' => 'Crimson', 'hex' => '#C0392B'],
                ['name' => 'Ocean', 'hex' => '#2471A3'],
            ]),
        ]);
        $base = "/puzzles/{$puzzle->id}/revisions/{$revision->id}/life-check";

        $this->fill($base, 0, 1)->assertOk()
            ->assertJsonPath('lives_remaining', 3)
            ->assertJsonMissingPath('is_correct')
            ->assertJsonMissingPath('solution_data');
        $this->fill($base, 0, 2)->assertOk()->assertJsonPath('lives_remaining', 2);
        $this->fill($base, 0, 3)->assertUnprocessable();
        $this->postJson($base, ['cell_index' => 0, 'guess_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_life_tiers_use_total_board_cells(): void
    {
        $owner = User::factory()->create();
        foreach ([[10, 10, 3], [11, 10, 5], [21, 20, 7]] as [$width, $height, $expected]) {
            $puzzle = $this->publish($owner, $width, $height, str_repeat('1', $width * $height));
            $revision = $puzzle->publishedRevision;
            $this->getJson("/puzzles/{$puzzle->id}/revisions/{$revision->id}/game-session")
                ->assertOk()->assertJsonPath('initial_lives', $expected);
        }
    }
}
