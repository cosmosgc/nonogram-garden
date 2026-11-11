<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PuzzlePublishingTest extends TestCase
{
    use RefreshDatabase;

    private function ownedPuzzle(User $owner, int $width, int $height, string $cells): Puzzle
    {
        return Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => $width,
            'height' => $height,
            'draft_cells' => $cells,
        ]);
    }

    public function test_owner_can_publish_a_unique_puzzle_as_an_immutable_revision(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');

        $response = $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])
            ->assertCreated()
            ->assertJsonPath('status', 'published')
            ->assertJsonPath('uniqueness', 'unique')
            ->assertJsonPath('revision_number', 1)
            ->assertJsonPath('moderation_status', 'pending');

        $revision = $puzzle->fresh()->publishedRevision;
        $this->assertNotNull($revision);
        $this->assertSame($response->json('revision_id'), $revision->id);
        $this->assertSame('1', $revision->solution_data);
        $this->assertSame([[1]], $revision->row_clues);
        $this->assertTrue($revision->is_unique);
        $this->assertDatabaseHas('puzzles', ['id' => $puzzle->id, 'status' => 'published', 'visibility' => 'public', 'moderation_status' => 'pending']);
    }

    public function test_public_puzzle_responses_expose_clues_but_never_the_solution(): void
    {
        $owner = User::factory()->create(['username' => 'creator']);
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');
        $puzzle->update(['tags' => ['animals']]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $this->getJson("/puzzles/{$puzzle->id}")
            ->assertOk()
            ->assertJsonPath('row_clues', [[1]])
            ->assertJsonPath('tags', ['animals'])
            ->assertJsonPath('start_hint', null)
            ->assertJsonPath('author.username', 'creator')
            ->assertJsonMissingPath('solution_data')
            ->assertJsonMissingPath('solution')
            ->assertJsonMissingPath('grid');
        $this->get("/puzzles/{$puzzle->id}")->assertOk()
            ->assertSee('animals')
            ->assertSee('data-progress-url="'.route('puzzles.progress.show', ['puzzle' => $puzzle->id, 'revision' => $puzzle->fresh()->published_revision_id]).'"', false)
            ->assertSee('data-completion-url="'.route('puzzles.progress.complete', ['puzzle' => $puzzle->id, 'revision' => $puzzle->fresh()->published_revision_id]).'"', false)
            ->assertDontSee('solution_data');
    }

    public function test_game_start_hint_reveals_one_row_and_column_and_is_stable_in_the_session(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 2, 2, '1111');
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $url = route('puzzles.show', $puzzle);
        $response = $this->getJson($url)->assertOk()->assertJsonMissingPath('solution_data')->assertJsonMissingPath('solution');
        $hint = $response->json('start_hint');

        $this->assertSame('11', $hint['row_cells']);
        $this->assertSame('11', $hint['column_cells']);
        $this->assertGreaterThanOrEqual(0, $hint['row_index']);
        $this->assertLessThan(2, $hint['row_index']);
        $this->assertGreaterThanOrEqual(0, $hint['column_index']);
        $this->assertLessThan(2, $hint['column_index']);
        $this->getJson($url)->assertOk()->assertJsonPath('start_hint', $hint);
    }

    public function test_single_row_or_column_puzzles_do_not_receive_a_hint_that_would_reveal_the_whole_solution(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $this->getJson(route('puzzles.show', $puzzle))->assertOk()->assertJsonPath('start_hint', null);
    }

    public function test_user_description_is_escaped_in_html_and_remains_plain_json_text(): void
    {
        $owner = User::factory()->create();
        $payload = '<script>alert(1)</script><img src=x onerror=alert(2)>'."\n".'Description';
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');
        $puzzle->update(['description' => $payload]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $this->get("/puzzles/{$puzzle->id}")
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
        $this->getJson("/puzzles/{$puzzle->id}")->assertOk()->assertJsonPath('description', $payload);
    }

    public function test_unpublished_puzzle_is_not_publicly_discoverable(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');

        $this->getJson("/puzzles/{$puzzle->id}")->assertNotFound();
    }

    public function test_published_revision_cannot_be_mutated(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');
        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;

        $this->expectException(\LogicException::class);
        $revision->update(['solution_data' => '0']);
    }

    public function test_unpublish_and_republish_preserve_immutable_revision_history_and_progress(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');
        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $firstRevision = $puzzle->fresh()->publishedRevision;

        $player = User::factory()->create();
        $progressUrl = "/puzzles/{$puzzle->id}/revisions/{$firstRevision->id}/progress";
        $this->actingAs($player)->putJson($progressUrl, ['marks' => '1', 'elapsed_seconds' => 12, 'version' => 0])->assertOk();

        $this->actingAs($owner)->postJson("/puzzles/{$puzzle->id}/unpublish", ['confirm' => true])
            ->assertOk()->assertJsonPath('status', 'draft');
        $this->getJson("/puzzles/{$puzzle->id}")->assertNotFound();
        $this->getJson($progressUrl)->assertNotFound();
        $this->assertDatabaseHas('puzzle_revisions', ['id' => $firstRevision->id, 'solution_data' => '1']);
        $this->assertDatabaseHas('puzzle_progress', ['user_id' => $player->id, 'puzzle_revision_id' => $firstRevision->id, 'marks' => '1']);

        $this->get("/editor/{$puzzle->id}")->assertOk();
        $this->putJson("/puzzles/drafts/{$puzzle->id}", [
            'version' => 2,
            'title' => 'Edited revision',
            'grid' => ['version' => 1, 'width' => 1, 'height' => 1, 'cells' => '0'],
        ])->assertOk()->assertJsonPath('version', 3);
        $this->publishAndApprove($puzzle, $owner)
            ->assertCreated()->assertJsonPath('revision_number', 2);

        $secondRevision = $puzzle->fresh()->publishedRevision;
        $this->assertNotSame($firstRevision->id, $secondRevision->id);
        $this->assertSame('1', $firstRevision->fresh()->solution_data);
        $this->assertSame('0', $secondRevision->solution_data);
        $this->assertDatabaseCount('puzzle_revisions', 2);
        $this->assertDatabaseCount('puzzle_progress', 1);
        $this->getJson("/puzzles/{$puzzle->id}")->assertOk()->assertJsonPath('revision_id', $secondRevision->id);
        $this->getJson(route('puzzles.revisions.show', ['puzzle' => $puzzle->id, 'revision' => $firstRevision->id]))
            ->assertOk()->assertJsonPath('revision_id', $firstRevision->id)->assertJsonPath('row_clues', [[1]]);
    }

    public function test_only_owner_can_unpublish_and_confirmation_is_required(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');
        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])->assertCreated();

        $this->actingAs($other)->postJson("/puzzles/{$puzzle->id}/unpublish", ['confirm' => true])->assertForbidden();
        $this->actingAs($owner)->postJson("/puzzles/{$puzzle->id}/unpublish", ['confirm' => false])->assertUnprocessable();
        $this->assertDatabaseHas('puzzles', ['id' => $puzzle->id, 'status' => 'published']);
    }

    public function test_ambiguous_puzzle_is_not_published_or_snapshotted(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 2, 2, '1001');

        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])
            ->assertUnprocessable()->assertJsonPath('uniqueness', 'ambiguous');

        $this->assertDatabaseHas('puzzles', ['id' => $puzzle->id, 'status' => 'draft', 'published_revision_id' => null]);
        $this->assertDatabaseCount('puzzle_revisions', 0);
    }

    public function test_publish_requires_confirmation_and_owner_authorization(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $puzzle = $this->ownedPuzzle($owner, 1, 1, '1');

        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => false])->assertUnprocessable();
        $this->actingAs($other)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('puzzle_revisions', 0);
    }
}
