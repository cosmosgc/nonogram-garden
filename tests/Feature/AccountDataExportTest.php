<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_can_download_only_their_own_account_data(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'My private art',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        $this->actingAs($owner)->putJson(route('puzzles.progress.save', [$puzzle, $revision]), [
            'marks' => '0', 'elapsed_seconds' => 37, 'version' => 0,
        ])->assertOk();
        $collection = $owner->collections()->create([
            'title' => 'My set', 'slug' => 'my-set', 'visibility' => 'private',
        ]);
        $collection->puzzles()->attach($puzzle->id, ['position' => 1]);

        $other = User::factory()->create(['email' => 'other@example.test']);
        Puzzle::factory()->create(['user_id' => $other->id, 'title' => 'Someone else draft']);

        $this->actingAs($owner)->get(route('account.export'))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="nonogram-account-export-'.$owner->id.'.json"')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertJsonPath('profile.email', 'owner@example.test')
            ->assertJsonPath('puzzles.0.title', 'My private art')
            ->assertJsonPath('puzzles.0.revisions.0.solution_data', '1')
            ->assertJsonPath('progress.0.elapsed_seconds', 37)
            ->assertJsonPath('collections.0.title', 'My set')
            ->assertDontSee('other@example.test')
            ->assertDontSee('Someone else draft')
            ->assertJsonMissingPath('profile.password');
    }

    public function test_account_export_requires_authentication_and_has_a_dedicated_download_limit(): void
    {
        $this->get(route('account.export'))->assertRedirect(route('login'));
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('account.export'))->assertOk();
        $this->get(route('account.export'))->assertOk();
        $this->get(route('account.export'))->assertTooManyRequests();
    }

    public function test_account_deletion_removes_owned_content_and_dependent_progress(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Delete with owner',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        $player = User::factory()->create();
        $this->actingAs($player)->putJson(route('puzzles.progress.save', [$puzzle, $revision]), [
            'marks' => '0', 'elapsed_seconds' => 12, 'version' => 0,
        ])->assertOk();
        $collection = $owner->collections()->create([
            'title' => 'Delete with owner', 'slug' => 'delete-with-owner', 'visibility' => 'public',
        ]);
        $collection->puzzles()->attach($puzzle->id, ['position' => 1]);

        $this->actingAs($owner)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('puzzles', ['id' => $puzzle->id]);
        $this->assertDatabaseMissing('puzzle_revisions', ['id' => $revision->id]);
        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        $this->assertDatabaseCount('puzzle_progress', 0);
        $this->assertDatabaseCount('collection_puzzle', 0);
    }
}
