<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('nonogram:moderator {email} {--revoke}', function (string $email) {
    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $this->error('No account matches that email address.');

        return self::FAILURE;
    }

    $user->forceFill(['is_moderator' => ! $this->option('revoke')])->save();
    $this->info($this->option('revoke') ? 'Moderator access revoked.' : 'Moderator access granted.');

    return self::SUCCESS;
})->purpose('Grant or revoke puzzle moderation access');

Artisan::command('nonogram:admin {email} {--revoke}', function (string $email) {
    $user = User::query()->where('email', $email)->first();
    if ($user === null) {
        $this->error('No account matches that email address.');

        return self::FAILURE;
    }

    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($this->option('revoke') ? 'Administrator access revoked.' : 'Administrator access granted.');

    return self::SUCCESS;
})->purpose('Grant or revoke site administrator access');
