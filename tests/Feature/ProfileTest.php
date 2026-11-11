<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_user_can_upload_an_avatar_that_is_reencoded_as_png(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->image('avatar.png', 24, 24),
        ]);

        $response->assertRedirect('/profile')->assertSessionHasNoErrors();

        $avatarPath = $user->fresh()->avatar_path;
        $this->assertMatchesRegularExpression('/\Aavatars\/\d+\/[0-9a-f-]{36}\.png\z/', $avatarPath);
        Storage::disk('public')->assertExists($avatarPath);
        $this->assertSame(IMAGETYPE_PNG, getimagesize(Storage::disk('public')->path($avatarPath))[2]);
    }

    public function test_avatar_upload_rejects_non_image_content_and_oversized_dimensions(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $attributes = ['name' => $user->name, 'email' => $user->email];

        $this->actingAs($user)->from('/profile')->patch('/profile', $attributes + [
            'avatar' => UploadedFile::fake()->createWithContent('avatar.png', 'not an image'),
        ])->assertRedirect('/profile')->assertSessionHasErrors('avatar');

        $png = UploadedFile::fake()->image('source.png', 20, 20);
        $this->actingAs($user)->from('/profile')->patch('/profile', $attributes + [
            'avatar' => UploadedFile::fake()->createWithContent('mismatched.jpg', (string) file_get_contents($png->getPathname())),
        ])->assertRedirect('/profile')->assertSessionHasErrors('avatar');

        $this->actingAs($user)->from('/profile')->patch('/profile', $attributes + [
            'avatar' => UploadedFile::fake()->image('large.png', 1025, 20),
        ])->assertRedirect('/profile')->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_avatar_upload_rejects_files_larger_than_two_megabytes(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user)->from('/profile')->patch('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => UploadedFile::fake()->createWithContent('avatar.png', str_repeat('x', 2 * 1024 * 1024 + 1)),
        ])->assertRedirect('/profile')->assertSessionHasErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guest_cannot_upload_an_avatar(): void
    {
        Storage::fake('public');

        $this->patch('/profile', [
            'name' => 'Guest',
            'email' => 'guest@example.test',
            'avatar' => UploadedFile::fake()->image('avatar.png', 12, 12),
        ])->assertRedirect(route('login'));

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_replacing_avatar_deletes_the_previous_stored_file(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $attributes = ['name' => $user->name, 'email' => $user->email];

        $this->actingAs($user)->patch('/profile', $attributes + [
            'avatar' => UploadedFile::fake()->image('first.png', 12, 12),
        ])->assertRedirect('/profile');
        $previousAvatarPath = $user->fresh()->avatar_path;

        $this->actingAs($user)->patch('/profile', $attributes + [
            'avatar' => UploadedFile::fake()->image('second.png', 16, 16),
        ])->assertRedirect('/profile');

        $currentAvatarPath = $user->fresh()->avatar_path;
        $this->assertNotSame($previousAvatarPath, $currentAvatarPath);
        Storage::disk('public')->assertMissing($previousAvatarPath);
        Storage::disk('public')->assertExists($currentAvatarPath);
    }

    public function test_account_deletion_removes_the_users_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $avatarPath = "avatars/{$user->id}/123e4567-e89b-12d3-a456-426614174000.png";
        Storage::disk('public')->put($avatarPath, 'clean image bytes');
        $user->avatar_path = $avatarPath;
        $user->save();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        Storage::disk('public')->assertMissing($avatarPath);
        $this->assertNull($user->fresh());
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }
}
