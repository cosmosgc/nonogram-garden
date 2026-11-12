<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_navigation_has_a_visible_profile_settings_link(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');
        $response
            ->assertOk()
            ->assertSee('href="'.route('profile.edit').'"', false)
            ->assertSee('Edit profile')
            ->assertSee('<details class="site-menu" open>', false);

        $content = $response->getContent();
        $profileLinkPosition = strpos($content, 'href="'.route('profile.edit').'"');
        $menuPosition = strpos($content, '<details class="site-menu" open>');
        $this->assertIsInt($profileLinkPosition);
        $this->assertIsInt($menuPosition);
        $this->assertLessThan($menuPosition, $profileLinkPosition);
    }

    public function test_guest_navigation_does_not_show_the_profile_settings_link(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertDontSee('href="'.route('profile.edit').'"', false)
            ->assertSee('Log in');
    }

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
        $this->actingAs($user)->get('/profile')->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="bio"', false)
            ->assertSee('name="avatar"', false)
            ->assertSee('enctype="multipart/form-data"', false);
    }

    public function test_public_profile_displays_avatar_without_disclosing_email(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['username' => 'avatar_creator']);

        $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.png', 20, 20),
        ])->assertRedirect(route('profile.edit'));

        $avatarPath = $user->fresh()->avatar_path;
        $this->get('/@avatar_creator')
            ->assertOk()
            ->assertSee('/storage/'.$avatarPath)
            ->assertDontSee($user->email);
    }
}
