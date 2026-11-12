<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\Nonogram\PuzzleImageStorage;
use App\Services\Profile\AvatarImageProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, AvatarImageProcessor $avatarImageProcessor): RedirectResponse
    {
        $validated = $request->validated();
        $avatar = $validated['avatar'] ?? null;
        unset($validated['avatar']);

        $user = $request->user();
        $previousAvatarPath = $user->avatar_path;
        $newAvatarPath = null;

        if ($avatar !== null) {
            $newAvatarPath = "avatars/{$user->id}/".Str::uuid().'.png';
            $cleanPng = $avatarImageProcessor->toCleanPng($avatar);

            if (! Storage::disk('public')->put($newAvatarPath, $cleanPng, 'public')) {
                throw new RuntimeException('The avatar could not be stored.');
            }
        }

        try {
            $user->fill($validated);

            if ($user->isDirty('email')) {
                $user->email_verified_at = null;
            }

            if ($newAvatarPath !== null) {
                $user->avatar_path = $newAvatarPath;
            }

            if (! $user->save()) {
                throw new RuntimeException('The profile could not be saved.');
            }
        } catch (Throwable $exception) {
            if ($newAvatarPath !== null) {
                Storage::disk('public')->delete($newAvatarPath);
            }

            throw $exception;
        }

        if ($newAvatarPath !== null && $previousAvatarPath !== null) {
            $this->deleteStoredAvatar($previousAvatarPath, $user->id);
        }

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request, PuzzleImageStorage $puzzleImageStorage): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $puzzleIds = $user->puzzles()->pluck('id');

        Auth::logout();

        if (! $user->delete()) {
            throw new RuntimeException('The account could not be deleted.');
        }

        if ($user->avatar_path !== null) {
            $this->deleteStoredAvatar($user->avatar_path, $user->id);
        }

        foreach ($puzzleIds as $puzzleId) {
            $puzzleImageStorage->deleteForPuzzle((int) $puzzleId);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    private function deleteStoredAvatar(string $path, int $userId): void
    {
        if (preg_match('~\Aavatars/'.preg_quote((string) $userId, '~').'\/[0-9a-f-]{36}\.png\z~', $path) === 1) {
            Storage::disk('public')->delete($path);
        }
    }
}
