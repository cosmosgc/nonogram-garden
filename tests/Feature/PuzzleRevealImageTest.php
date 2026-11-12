<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PuzzleRevealImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploaded_artwork_is_private_pinned_to_revision_and_only_revealed_after_that_players_verified_completion(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $sourceImage = UploadedFile::fake()->image('source.png', 4, 3, 'red');
        $revealImage = UploadedFile::fake()->image('reveal-image.png', 4, 3, 'blue');

        $created = $this->actingAs($owner)->postJson('/puzzles/drafts', [
            'title' => 'Artwork-backed puzzle',
            'grid' => ['version' => 1, 'width' => 1, 'height' => 1, 'cells' => '1'],
            'source_image' => $sourceImage,
            'reveal_image' => $revealImage,
        ])->assertCreated();

        $puzzle = Puzzle::query()->findOrFail($created->json('id'));
        self::assertNotNull($puzzle->draft_original_image_path);
        self::assertNotNull($puzzle->draft_reveal_image_path);
        Storage::disk('local')->assertExists($puzzle->draft_original_image_path);
        Storage::disk('local')->assertExists($puzzle->draft_reveal_image_path);
        self::assertStringStartsWith("puzzle-images/{$puzzle->id}/", $puzzle->draft_original_image_path);
        self::assertStringStartsWith("puzzle-images/{$puzzle->id}/", $puzzle->draft_reveal_image_path);
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", Storage::disk('local')->get($puzzle->draft_reveal_image_path));

        $this->publishAndApprove($puzzle, $owner)->assertCreated();
        $revision = $puzzle->fresh()->publishedRevision;
        self::assertSame($puzzle->draft_original_image_path, $revision->original_image_path);
        self::assertSame($puzzle->draft_reveal_image_path, $revision->reveal_image_path);
        $revealUrl = route('puzzles.revisions.reveal-image', ['puzzle' => $puzzle, 'revision' => $revision]);

        auth()->logout();
        $this->get("/puzzles/{$puzzle->id}")->assertOk()->assertDontSee('data-reveal-image-url=', false);
        $this->get($revealUrl)->assertRedirect(route('login'));

        $player = User::factory()->create();
        $this->actingAs($player)->get("/puzzles/{$puzzle->id}")
            ->assertOk()
            ->assertSee('data-reveal-image-url="'.$revealUrl.'"', false);
        $this->get($revealUrl)->assertNotFound();
        $this->putJson(route('puzzles.progress.save', ['puzzle' => $puzzle, 'revision' => $revision]), [
            'marks' => '1',
            'elapsed_seconds' => 12,
            'version' => 0,
        ])->assertOk()->assertJsonPath('progress.completed_at', fn ($completedAt) => is_string($completedAt));

        $revealedImage = $this->get($revealUrl);
        $revealedImage
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        self::assertStringContainsString('private', strtolower((string) $revealedImage->headers->get('Cache-Control')));
        self::assertStringContainsString('no-store', strtolower((string) $revealedImage->headers->get('Cache-Control')));

        $anotherPlayer = User::factory()->create();
        $this->actingAs($anotherPlayer)->get($revealUrl)->assertNotFound();
    }

    public function test_image_uploads_must_be_a_valid_pair_of_supported_images(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJson('/puzzles/drafts', [
            'grid' => ['version' => 1, 'width' => 1, 'height' => 1, 'cells' => '1'],
            'source_image' => UploadedFile::fake()->create('not-an-image.png', 2, 'image/png'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['reveal_image', 'source_image']);

        $this->assertDatabaseCount('puzzles', 0);
        Storage::disk('local')->assertDirectoryEmpty('puzzle-images');
    }
}
