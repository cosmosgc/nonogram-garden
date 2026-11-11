<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_set_a_normalized_public_username_and_bio_without_exposing_email(): void
    {
        $user = User::factory()->create();
        $bio = '<script>alert(1)</script>'."\n".'Puzzle creator';
        $this->actingAs($user)->patch('/profile', [
            'name' => 'Creator Name',
            'email' => $user->email,
            'username' => '  Picross_Fan  ',
            'bio' => $bio,
        ])->assertRedirect(route('profile.edit'));

        $user->refresh();
        $this->assertSame('picross_fan', $user->username);
        $this->assertSame($bio, $user->bio);
        $this->get('/@PICROSS_FAN')
            ->assertOk()
            ->assertSee('Creator Name')
            ->assertSee('@picross_fan')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee($user->email);
    }

    public function test_public_profile_only_lists_public_published_puzzles(): void
    {
        $owner = User::factory()->create(['username' => 'puzzle_maker']);
        $public = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Public creation',
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($public, $owner)->assertCreated();
        Puzzle::factory()->create(['user_id' => $owner->id, 'title' => 'Private draft']);
        Puzzle::factory()->create(['user_id' => $owner->id, 'title' => 'Unlisted item', 'status' => 'published', 'visibility' => 'unlisted']);
        $owner->collections()->create(['title' => 'Public set', 'slug' => 'public-set', 'visibility' => 'public']);
        $owner->collections()->create(['title' => 'Private set', 'slug' => 'private-set', 'visibility' => 'private']);

        $this->get('/@puzzle_maker')
            ->assertOk()
            ->assertSee('Public creation')
            ->assertDontSee('Private draft')
            ->assertDontSee('Unlisted item')
            ->assertSee('Public set')
            ->assertDontSee('Private set')
            ->assertDontSee($owner->email)
            ->assertDontSee('solution_data');
    }

    public function test_username_rules_reject_reserved_invalid_and_duplicate_names(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $update = fn (User $user, string $username) => $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'username' => $username,
            'bio' => null,
        ]);

        $update($first, 'Admin')->assertSessionHasErrors('username');
        $update($first, 'bad-name')->assertSessionHasErrors('username');
        $update($first, 'ab')->assertSessionHasErrors('username');
        $update($first, 'valid_user')->assertRedirect(route('profile.edit'));
        $update($second, 'VALID_USER')->assertSessionHasErrors('username');

        $this->assertSame('valid_user', $first->fresh()->username);
        $this->assertNull($second->fresh()->username);
    }

    public function test_profile_edit_form_exposes_username_and_bio_fields(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/profile')->assertOk()->assertSee('name="username"', false)->assertSee('name="bio"', false);
    }
}
