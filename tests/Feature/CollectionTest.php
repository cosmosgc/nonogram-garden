<?php

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CollectionTest extends TestCase
{
    use RefreshDatabase;

    private function publish(User $owner, string $title, int $width = 1, int $height = 1, string $cells = '1'): Puzzle
    {
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => $title,
            'width' => $width,
            'height' => $height,
            'draft_cells' => $cells,
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        return $puzzle->fresh();
    }

    private function createCollection(User $owner, string $title = 'Favorites', string $visibility = 'private'): Collection
    {
        $this->actingAs($owner)->post('/collections', [
            'title' => $title,
            'description' => 'A curated set',
            'visibility' => $visibility,
        ])->assertRedirect();

        return Collection::query()->where('user_id', $owner->id)->where('title', $title)->firstOrFail();
    }

    public function test_collection_slug_is_global_unique_and_stable_when_title_changes(): void
    {
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $first = $this->createCollection($firstOwner, 'Create');
        $second = $this->createCollection($secondOwner, 'Create');

        $this->assertSame('create-collection', $first->slug);
        $this->assertSame('create-collection-2', $second->slug);
        $this->actingAs($firstOwner)->put(route('collections.update', $first), [
            'title' => 'Renamed set', 'description' => null, 'visibility' => 'public',
        ])->assertRedirect();
        $this->assertSame('create-collection', $first->fresh()->slug);
        $this->get('/collections/create-collection')->assertOk()->assertSee('Renamed set');
    }

    public function test_owner_can_transactionally_order_remove_and_reorder_only_public_puzzles(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->publish($owner, 'Alpha');
        $beta = $this->publish($owner, 'Beta', 2, 1, '10');
        $private = Puzzle::factory()->create(['user_id' => $owner->id, 'title' => 'Private draft']);
        $collection = $this->createCollection($owner, 'Favorites', 'public');
        $syncUrl = route('collections.puzzles.sync', $collection);

        $this->actingAs($owner)->put($syncUrl, [
            'positions' => [$beta->id => 2, $alpha->id => 1, $private->id => 0],
        ])->assertRedirect();
        $this->get(route('collections.show', $collection))->assertOk()->assertSeeInOrder(['Alpha', 'Beta'])->assertDontSee('Private draft');

        $this->put($syncUrl, ['positions' => [$alpha->id => 2, $beta->id => 1]])->assertRedirect();
        $this->get(route('collections.show', $collection))->assertOk()->assertSeeInOrder(['Beta', 'Alpha']);

        $this->put($syncUrl, ['positions' => [$alpha->id => 0, $beta->id => 1]])->assertRedirect();
        $this->assertSame([$beta->id], $collection->fresh()->puzzles->pluck('id')->all());
    }

    public function test_collection_sync_rejects_non_public_puzzles_and_duplicate_positions_atomically(): void
    {
        $owner = User::factory()->create();
        $public = $this->publish($owner, 'Allowed');
        $draft = Puzzle::factory()->create(['user_id' => $owner->id]);
        $collection = $this->createCollection($owner, 'Private picks');
        $syncUrl = route('collections.puzzles.sync', $collection);

        $this->actingAs($owner)->put($syncUrl, ['positions' => [$public->id => 1, $draft->id => 2]])->assertSessionHasErrors('positions');
        $this->put($syncUrl, ['positions' => [$public->id => 1, $draft->id => 1]])->assertSessionHasErrors('positions');
        $this->assertDatabaseCount('collection_puzzle', 0);
    }

    public function test_private_unlisted_and_public_collection_visibility_is_enforced(): void
    {
        $owner = User::factory()->create();
        $public = $this->createCollection($owner, 'Public set', 'public');
        $unlisted = $this->createCollection($owner, 'Secret set', 'unlisted');
        $private = $this->createCollection($owner, 'Private set', 'private');
        $other = User::factory()->create();

        auth()->logout();
        $this->get('/collections')->assertOk()->assertSee('Public set')->assertDontSee('Secret set')->assertDontSee('Private set');
        $this->get(route('collections.show', $public))->assertOk();
        $this->get(route('collections.show', $unlisted))->assertOk();
        $this->get(route('collections.show', $private))->assertNotFound();
        $this->actingAs($other)->get(route('collections.show', $private))->assertNotFound();
        $this->actingAs($owner)->get('/collections')->assertOk()->assertSee('Secret set')->assertSee('Private set');
    }

    public function test_database_rejects_duplicate_collection_membership(): void
    {
        $owner = User::factory()->create();
        $puzzle = $this->publish($owner, 'One puzzle');
        $collection = $this->createCollection($owner);
        $collection->puzzles()->attach($puzzle->id, ['position' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        $collection->puzzles()->attach($puzzle->id, ['position' => 2]);
    }

    public function test_only_collection_owner_can_manage_or_delete_it(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $puzzle = $this->publish($owner, 'Kept after collection deletion');
        $collection = $this->createCollection($owner);
        $collection->puzzles()->attach($puzzle->id, ['position' => 1]);

        $this->actingAs($other)->get(route('collections.edit', $collection))->assertForbidden();
        $this->actingAs($other)->put(route('collections.update', $collection), [
            'title' => 'Stolen', 'description' => null, 'visibility' => 'public',
        ])->assertForbidden();
        $this->delete(route('collections.destroy', $collection))->assertForbidden();
        $this->assertDatabaseHas('collections', ['id' => $collection->id, 'user_id' => $owner->id]);

        $this->actingAs($owner)->delete(route('collections.destroy', $collection))->assertRedirect(route('collections.index'));
        $this->assertDatabaseMissing('collections', ['id' => $collection->id]);
        $this->assertDatabaseCount('collection_puzzle', 0);
        $this->assertDatabaseHas('puzzles', ['id' => $puzzle->id]);
    }
}
