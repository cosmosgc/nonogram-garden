<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PuzzleDraftTest extends TestCase
{
    use RefreshDatabase;

    private function grid(string $cells = '0110'): array
    {
        return ['version' => 1, 'width' => 2, 'height' => 2, 'cells' => $cells];
    }

    public function test_owner_can_create_fetch_and_update_private_draft(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $created = $this->postJson('/puzzles/drafts', ['title' => 'Tiny', 'description' => 'A test', 'tags' => ['logic', 'ocean-life'], 'grid' => $this->grid()])
            ->assertCreated()
            ->assertJsonPath('title', 'Tiny')
            ->assertJsonPath('description', 'A test')
            ->assertJsonPath('tags', ['logic', 'ocean-life'])
            ->assertJsonPath('version', 1)
            ->assertJsonStructure(['updated_at'])
            ->assertJsonPath('clues.rows.0.0', 1)
            ->assertJsonMissingPath('user_id');

        $id = $created->json('id');
        $this->getJson("/puzzles/drafts/{$id}")
            ->assertOk()
            ->assertJsonPath('grid.cells', '0110');

        $this->putJson("/puzzles/drafts/{$id}", [
            'version' => 1,
            'title' => 'Updated',
            'description' => null,
            'tags' => ['animals'],
            'grid' => $this->grid('1001'),
        ])->assertOk()->assertJsonPath('version', 2)->assertJsonPath('title', 'Updated')->assertJsonPath('tags', ['animals']);

        $this->assertDatabaseHas('puzzles', [
            'id' => $id,
            'user_id' => $owner->id,
            'title' => 'Updated',
            'tags' => '["animals"]',
            'description' => null,
            'draft_cells' => '1001',
            'draft_version' => 2,
            'visibility' => 'private',
        ]);
    }

    public function test_owner_can_save_and_reload_a_color_draft_with_its_palette(): void
    {
        $owner = User::factory()->create();
        $palette = [
            ['name' => 'Crimson', 'hex' => '#C0392B'],
            ['name' => 'Ocean', 'hex' => '#2471A3'],
        ];
        $document = [
            'mode' => 'color',
            'rules_version' => 1,
            'width' => 4,
            'height' => 1,
            'cells' => '1122',
            'palette' => $palette,
        ];

        $created = $this->actingAs($owner)->postJson('/puzzles/drafts', [
            'title' => 'Color sample',
            'grid' => $document,
        ])->assertCreated()
            ->assertJsonPath('grid.mode', 'color')
            ->assertJsonPath('grid.rules_version', 1)
            ->assertJsonPath('grid.palette', $palette)
            ->assertJsonPath('clues.rows.0', [[2, 1], [2, 2]]);

        $id = $created->json('id');
        $this->assertDatabaseHas('puzzles', [
            'id' => $id,
            'draft_mode' => 'color',
            'draft_rules_version' => 1,
            'draft_cells' => '1122',
        ]);

        $this->getJson("/puzzles/drafts/{$id}")
            ->assertOk()
            ->assertJsonPath('grid.palette', $palette)
            ->assertJsonPath('clues.columns.3.0', [1, 2]);

        $updatedPalette = [
            ['name' => 'Red', 'hex' => '#C0392B'],
            ['name' => 'Blue', 'hex' => '#2471A3'],
        ];
        $this->putJson("/puzzles/drafts/{$id}", [
            'version' => 1,
            'grid' => [...$document, 'palette' => $updatedPalette],
        ])->assertOk()->assertJsonPath('grid.palette', $updatedPalette)->assertJsonPath('version', 2);

        $this->assertSame($updatedPalette, $owner->puzzles()->findOrFail($id)->fresh()->draft_palette);
    }

    public function test_color_draft_rejects_duplicate_palette_colors_and_missing_palette(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);

        $base = ['mode' => 'color', 'width' => 1, 'height' => 1, 'cells' => '1'];
        $duplicatePalette = [
            ['name' => 'One', 'hex' => '#C0392B'],
            ['name' => 'Two', 'hex' => '#C0392B'],
        ];
        $this->postJson('/puzzles/drafts', ['grid' => [...$base, 'palette' => $duplicatePalette]])
            ->assertUnprocessable();
        $this->postJson('/puzzles/drafts', ['grid' => $base])->assertUnprocessable();
        $this->assertDatabaseCount('puzzles', 0);
    }

    public function test_draft_creation_is_rate_limited(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/puzzles/drafts', [])->assertUnprocessable();
        }
        $this->postJson('/puzzles/drafts', [])->assertTooManyRequests();
    }

    public function test_username_is_normalized_and_unique(): void
    {
        $user = User::factory()->create(['username' => '  PicrossFan  ']);
        $this->assertSame('picrossfan', $user->fresh()->username);

        $this->expectException(UniqueConstraintViolationException::class);
        User::factory()->create(['username' => 'PICROSSFAN']);
    }

    public function test_puzzle_factory_creates_owner_relationship(): void
    {
        $puzzle = Puzzle::factory()->create();

        $this->assertNotNull($puzzle->owner);
        $this->assertSame($puzzle->user_id, $puzzle->owner->id);
    }

    public function test_editor_page_uses_framework_routes_with_a_subdirectory_url_root(): void
    {
        $owner = User::factory()->create();
        URL::forceRootUrl('http://localhost:3000/Nonogram%20laravel/public');

        $this->actingAs($owner);
        $html = view('editor', ['puzzle' => null])->render();

        $this->assertStringContainsString('data-draft-store-url="http://localhost:3000/Nonogram%20laravel/public/puzzles/drafts"', $html);
        $this->assertStringContainsString('data-editor-new-url="http://localhost:3000/Nonogram%20laravel/public/editor/new"', $html);
        $this->assertStringContainsString('data-editor-edit-url-template="http://localhost:3000/Nonogram%20laravel/public/editor/__PUZZLE_ID__"', $html);
    }

    public function test_editor_page_requires_authentication_and_loads_owned_draft(): void
    {
        $guest = $this->get('/editor/new');
        $guest->assertRedirect('/login');

        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($owner)
            ->get('/editor/new')->assertOk()->assertSee('nonogram-editor');
        $this->get("/editor/{$puzzle->id}")->assertOk()->assertSee('data-puzzle-id="'.$puzzle->id.'"', false);

        $other = User::factory()->create();
        $this->actingAs($other)->get("/editor/{$puzzle->id}")->assertForbidden();
    }

    public function test_puzzle_can_reference_a_published_revision(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create(['user_id' => $owner->id]);
        $revision = $puzzle->revisions()->create([
            'revision_number' => 1,
            'width' => 2,
            'height' => 2,
            'format_version' => 1,
            'solution_data' => '0110',
            'row_clues' => [[1], [1]],
            'column_clues' => [[1], [1]],
            'solution_hash' => str_repeat('a', 64),
            'validation_status' => 'valid',
            'created_by' => $owner->id,
        ]);
        $puzzle->update(['published_revision_id' => $revision->id, 'published_at' => now()]);

        $this->assertSame($revision->id, $puzzle->fresh()->publishedRevision->id);
    }

    public function test_guest_cannot_create_or_read_private_draft(): void
    {
        $this->postJson('/puzzles/drafts', ['grid' => $this->grid()])->assertUnauthorized();

        $puzzle = new Puzzle([
            'title' => 'Private', 'width' => 2, 'height' => 2,
            'draft_cells' => '0000', 'status' => 'draft', 'visibility' => 'private',
        ]);
        $puzzle->owner()->associate(User::factory()->create())->save();
        $this->getJson("/puzzles/drafts/{$puzzle->id}")->assertUnauthorized();
    }

    public function test_other_user_cannot_read_or_update_draft(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $puzzle = new Puzzle([
            'title' => 'Private', 'width' => 2, 'height' => 2,
            'draft_cells' => '0000', 'status' => 'draft', 'visibility' => 'private',
        ]);
        $puzzle->owner()->associate($owner)->save();

        $this->actingAs($other)
            ->getJson("/puzzles/drafts/{$puzzle->id}")->assertForbidden();
        $this->putJson("/puzzles/drafts/{$puzzle->id}", ['version' => 1, 'grid' => $this->grid()])->assertForbidden();
    }

    public function test_invalid_grid_and_forged_ownership_fields_are_rejected_or_ignored(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);

        $this->postJson('/puzzles/drafts', ['grid' => $this->grid('0x00')])->assertUnprocessable();
        $this->postJson('/puzzles/drafts', ['tags' => ['Not a valid tag'], 'grid' => $this->grid()])
            ->assertUnprocessable()->assertJsonValidationErrors('tags.0');
        $response = $this->postJson('/puzzles/drafts', [
            'user_id' => $other->id,
            'status' => 'published',
            'visibility' => 'public',
            'grid' => $this->grid(),
        ])->assertCreated();
        $this->assertDatabaseHas('puzzles', [
            'id' => $response->json('id'),
            'user_id' => $owner->id,
            'status' => 'draft',
            'visibility' => 'private',
        ]);
    }

    public function test_stale_draft_version_returns_conflict_without_overwriting(): void
    {
        $owner = User::factory()->create();
        $puzzle = new Puzzle([
            'title' => 'Original', 'width' => 2, 'height' => 2,
            'draft_cells' => '0000', 'status' => 'draft', 'visibility' => 'private',
        ]);
        $puzzle->owner()->associate($owner)->save();

        $this->actingAs($owner)->putJson("/puzzles/drafts/{$puzzle->id}", [
            'version' => 2,
            'title' => 'Stale overwrite',
            'grid' => $this->grid('1111'),
        ])->assertStatus(409)->assertJsonPath('current_version', 1);

        $this->assertDatabaseHas('puzzles', ['id' => $puzzle->id, 'title' => 'Original', 'draft_cells' => '0000']);
    }
}
