<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('account-export', fn (Request $request) => Limit::perMinute(2)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('password-reset', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));
            $emailKey = $email === '' ? 'ip:'.$request->ip() : hash('sha256', $email);

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perHour(3)->by('email:'.$emailKey),
            ];
        });

        $limits = [
            'register.store' => 5,
            'login.store' => 30,
            'password.email' => 5,
            'password.store' => 10,
            'password.update' => 10,
            'password.confirm' => 10,
            'logout' => 10,
            'verification.verify' => 6,
            'verification.send' => 6,
            'collections.index' => 60,
            'collections.store' => 10,
            'collections.puzzles.sync' => 20,
            'collections.update' => 20,
            'collections.destroy' => 5,
            'collections.show' => 60,
            'profiles.show' => 60,
            'puzzles.index' => 60,
            'puzzles.revisions.show' => 60,
            'puzzles.show' => 60,
            'moderation.index' => 60,
            'moderation.update' => 20,
            'profile.update' => 10,
            'profile.destroy' => 5,
            'puzzles.progress.show' => 60,
            'puzzles.progress.save' => 30,
            'puzzles.progress.complete' => 10,
            'puzzles.game-session.show' => 60,
            'puzzles.game-session.mode' => 10,
            'puzzles.game-session.life-check' => 120,
            'puzzles.game-session.life-check.batch' => 30,
            'puzzles.game-session.restart' => 5,
            'puzzles.unpublish' => 5,
            'puzzles.drafts.store' => 10,
            'puzzles.drafts.show' => 60,
            'puzzles.drafts.update' => 30,
            'puzzles.drafts.publish' => 5,
        ];
        $publicReads = [
            'collections.index', 'collections.show', 'profiles.show', 'puzzles.index',
            'puzzles.revisions.show', 'puzzles.show',
        ];

        RateLimiter::for('application', function (Request $request) use ($limits, $publicReads) {
            $name = $request->route()?->getName() ?? $request->path();
            $identity = in_array($name, $publicReads, true)
                ? $request->ip()
                : (string) ($request->user()?->id ?? $request->ip());

            return Limit::perMinute($limits[$name] ?? 60)->by($name.'|'.$identity);
        });
    }
}
