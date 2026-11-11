<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\PuzzleProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PuzzleRulesetTest extends TestCase
{
    use RefreshDatabase;

    public function test_classic_publication_pins_the_mode_and_rules_version(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);

        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $revision = $puzzle->fresh()->publishedRevision;
        $this->assertSame('classic', $revision->mode);
        $this->assertSame(1, $revision->rules_version);
        $this->assertNull($revision->palette);
        $this->getJson(route('puzzles.show', $puzzle))
            ->assertOk()
            ->assertJsonPath('mode', 'classic')
            ->assertJsonPath('rules_version', 1)
            ->assertJsonPath('palette', null)
            ->assertJsonMissingPath('solution_data');
    }

    public function test_palette_persistence_round_trips_on_drafts_and_revisions(): void
    {
        $owner = User::factory()->create();
        $palette = [
            ['name' => 'Crimson', 'hex' => '#C0392B'],
            ['name' => 'Ocean', 'hex' => '#2471A3'],
        ];
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'draft_palette' => $palette,
        ]);

        $this->assertSame($palette, $puzzle->fresh()->draft_palette);

        $revision = $puzzle->revisions()->create([
            'revision_number' => 1,
            'width' => 2,
            'height' => 1,
            'mode' => 'color',
            'rules_version' => 1,
            'format_version' => 1,
            'solution_data' => '12',
            'palette' => $palette,
            'row_clues' => [[[1, 1], [1, 2]]],
            'column_clues' => [[[1, 1]], [[1, 2]]],
            'solution_hash' => str_repeat('a', 64),
            'is_unique' => true,
            'validation_status' => 'unique',
            'created_by' => $owner->id,
        ]);

        $this->assertSame($palette, $revision->fresh()->palette);
    }

    public function test_unsupported_draft_mode_cannot_be_published(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
            'draft_mode' => 'mega',
            'draft_rules_version' => 1,
        ]);

        $this->actingAs($owner)->postJson("/puzzles/drafts/{$puzzle->id}/publish", ['confirm' => true])
            ->assertUnprocessable()
            ->assertJsonPath('mode', 'mega');

        $this->assertSame('draft', $puzzle->fresh()->status);
        $this->assertSame(0, $puzzle->revisions()->count());
    }

    public function test_color_publication_pins_palette_and_never_exposes_solution_cells(): void
    {
        $owner = User::factory()->create();
        $palette = [
            ['name' => 'Crimson', 'hex' => '#C0392B'],
            ['name' => 'Ocean', 'hex' => '#2471A3'],
        ];
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => 3,
            'height' => 1,
            'draft_cells' => '120',
            'draft_mode' => 'color',
            'draft_rules_version' => 1,
            'draft_palette' => $palette,
        ]);

        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        $this->assertSame('color', $revision->mode);
        $this->assertSame(1, $revision->rules_version);
        $this->assertSame($palette, $revision->palette);
        $this->assertSame([[[1, 1], [1, 2]]], $revision->row_clues);

        $this->getJson(route('puzzles.show', $puzzle))
            ->assertOk()
            ->assertJsonPath('mode', 'color')
            ->assertJsonPath('palette', $palette)
            ->assertJsonMissingPath('solution_data');
    }

    public function test_unsupported_revision_rules_version_cannot_request_completion(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        DB::table('puzzle_revisions')->where('id', $revision->id)->update(['rules_version' => 2]);
        PuzzleProgress::query()->create([
            'user_id' => $owner->id,
            'puzzle_revision_id' => $revision->id,
            'marks' => '1',
            'elapsed_seconds' => 1,
            'mistake_count' => 0,
            'version' => 1,
            'last_played_at' => now(),
        ]);

        $this->actingAs($owner)->postJson(route('puzzles.progress.complete', ['puzzle' => $puzzle, 'revision' => $revision]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This puzzle rules version cannot be verified.');
    }
}
