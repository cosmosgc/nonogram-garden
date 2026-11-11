<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PuzzleBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_browse_lists_only_public_published_puzzles_without_solutions(): void
    {
        $owner = User::factory()->create();
        $public = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Public star',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($public, $owner)->assertCreated();

        $unlisted = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Hidden link',
            'status' => 'published',
            'visibility' => 'unlisted',
        ]);
        $draft = Puzzle::factory()->create(['user_id' => $owner->id, 'title' => 'Private draft']);

        $this->get('/puzzles')
            ->assertOk()
            ->assertSee('Browse puzzles')
            ->assertSee('Public star')
            ->assertDontSee('Hidden link')
            ->assertDontSee('Private draft')
            ->assertDontSee('solution_data')
            ->assertDontSee('draft_cells');
    }

    public function test_browse_and_puzzle_view_show_the_creator_avatar(): void
    {
        $owner = User::factory()->create(['name' => 'Avatar Creator', 'username' => 'avatar_creator']);
        $avatarPath = "avatars/{$owner->id}/creator.png";
        $owner->avatar_path = $avatarPath;
        $owner->save();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Puzzle with avatar',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $avatarUrl = asset('storage/'.$avatarPath);
        $this->get('/puzzles')
            ->assertOk()
            ->assertSee('src="'.$avatarUrl.'"', false)
            ->assertSee('Avatar Creator\'s profile avatar', false);
        $this->get(route('puzzles.show', $puzzle))
            ->assertOk()
            ->assertSee('src="'.$avatarUrl.'"', false)
            ->assertSee('Puzzle with avatar');
    }

    public function test_browse_shows_solution_preview_only_to_a_user_who_completed_that_revision(): void
    {
        $owner = User::factory()->create();
        $solver = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Completed star',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $puzzle->refresh();
        $solver->progressRecords()->create([
            'puzzle_revision_id' => $puzzle->published_revision_id,
            'marks' => '1',
            'elapsed_seconds' => 10,
            'mistake_count' => 0,
            'version' => 1,
            'completed_at' => now(),
            'last_played_at' => now(),
        ]);

        $this->actingAs($solver)->get('/puzzles')
            ->assertOk()
            ->assertSee('Solution image for Completed star')
            ->assertSee('fill="#236b50"', false);

        auth()->logout();
        $this->get('/puzzles')
            ->assertOk()
            ->assertDontSee('Solution image for Completed star')
            ->assertDontSee('solution_data');
    }

    public function test_pending_publication_is_hidden_from_browse_until_approved(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Waiting for review',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])->assertCreated();

        $this->get('/puzzles')
            ->assertOk()
            ->assertSee('No approved public puzzles match these filters')
            ->assertDontSee('Waiting for review');
        $this->get(route('puzzles.show', $puzzle))
            ->assertOk()
            ->assertSee('waiting for moderator approval');
    }

    public function test_browse_filters_by_dimensions_creator_and_whitelisted_recency(): void
    {
        $alice = User::factory()->create(['username' => 'alice']);
        $bob = User::factory()->create(['username' => 'bob']);
        $one = Puzzle::factory()->create(['user_id' => $alice->id, 'title' => 'One cell', 'width' => 1, 'height' => 1, 'draft_cells' => '1', 'tags' => ['animals']]);
        $two = Puzzle::factory()->create(['user_id' => $alice->id, 'title' => 'Two wide', 'width' => 2, 'height' => 1, 'draft_cells' => '10', 'tags' => ['logic']]);
        $three = Puzzle::factory()->create(['user_id' => $bob->id, 'title' => 'Another two', 'width' => 2, 'height' => 1, 'draft_cells' => '10', 'tags' => ['animals', 'logic']]);
        foreach ([$one, $two, $three] as $puzzle) {
            $this->publishAndApprove($puzzle, $puzzle->owner)->assertCreated();
        }
        $one->update(['published_at' => now()->subDays(3)]);
        $two->update(['published_at' => now()->subDays(2)]);
        $three->update(['published_at' => now()->subDay()]);

        $this->get('/puzzles?width=2&height=1&creator=ALICE&sort=oldest')
            ->assertOk()->assertSee('Two wide')->assertDontSee('One cell')->assertDontSee('Another two');
        $this->get('/puzzles?sort=oldest')->assertOk()->assertSeeInOrder(['One cell', 'Two wide', 'Another two']);
        $this->get('/puzzles?tag=animals')->assertOk()->assertSee('One cell')->assertSee('Another two')->assertDontSee('Two wide');
        $this->get('/puzzles?tag=DROP%20TABLE')->assertRedirect()->assertSessionHasErrors('tag');
        $this->get('/puzzles?width=31')->assertRedirect()->assertSessionHasErrors('width');
        $this->get('/puzzles?sort=title%3Bdrop+table')->assertRedirect()->assertSessionHasErrors('sort');
    }

    public function test_public_puzzle_detail_reads_are_rate_limited(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Rate limited detail',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        for ($request = 0; $request < 60; $request++) {
            $response = $this->get(route('puzzles.show', $puzzle));
            $this->assertSame(200, $response->getStatusCode(), "The route was throttled at request {$request}.");
        }
        $this->get(route('puzzles.show', $puzzle))->assertTooManyRequests();
    }

    public function test_browse_page_query_count_stays_bounded_for_a_page_of_public_puzzles(): void
    {
        for ($index = 0; $index < 12; $index++) {
            $owner = User::factory()->create();
            $puzzle = Puzzle::factory()->create([
                'user_id' => $owner->id,
                'title' => "Puzzle {$index}",
                'width' => 1,
                'height' => 1,
                'draft_cells' => '1',
            ]);
            $this->publishAndApprove($puzzle, $owner)->assertCreated();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/puzzles')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(7, $queryCount, 'Browse should eager-load owners and revisions instead of issuing per-puzzle queries.');
    }

    public function test_welcome_and_authenticated_navigation_link_to_the_editor_and_browse(): void
    {
        $this->get('/')->assertOk()->assertSee('Browse puzzles')->assertSee('Collections')->assertSee('Log in')->assertSee('Register');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/editor/new')
            ->assertOk()
            ->assertSee('Create puzzle')
            ->assertSee('Browse puzzles')
            ->assertSee('Collections')
            ->assertSee(route('puzzles.index'));
        $this->get('/dashboard')->assertOk()->assertSee('Your recent puzzles')->assertSee('Create a puzzle')->assertSee('Import from image');
        $this->get(route('editor.import'))->assertOk()
            ->assertSee('nonogram-image-importer')
            ->assertSee('data-editor-new-url="'.route('editor.new').'"', false)
            ->assertSee('Loading the image importer')
            ->assertSee('Import an image');
    }

    public function test_dashboard_links_owned_drafts_and_published_puzzles_to_the_right_pages(): void
    {
        $owner = User::factory()->create();
        $draft = Puzzle::factory()->create(['user_id' => $owner->id, 'title' => 'My draft']);
        $published = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'My published puzzle',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($published, $owner)->assertCreated();

        $this->actingAs($owner)->get('/dashboard')
            ->assertOk()
            ->assertSee('My draft')
            ->assertSee(route('editor.edit', $draft))
            ->assertSee('My published puzzle')
            ->assertSee(route('puzzles.show', $published));
    }
}
