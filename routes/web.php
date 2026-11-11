<?php

use App\Http\Controllers\AccountDataExportController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\CompletePuzzleController;
use App\Http\Controllers\ModerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\PublicPuzzleController;
use App\Http\Controllers\PublishPuzzleController;
use App\Http\Controllers\PuzzleBrowseController;
use App\Http\Controllers\PuzzleDraftController;
use App\Http\Controllers\PuzzleGameSessionController;
use App\Http\Controllers\PuzzleProgressController;
use App\Http\Controllers\UnpublishPuzzleController;
use App\Models\Puzzle;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/@{username}', PublicProfileController::class)->middleware('throttle:application')
    ->where('username', '[A-Za-z0-9_]{3,30}')->name('profiles.show');
Route::get('/collections', [CollectionController::class, 'index'])->middleware('throttle:application')->name('collections.index');
Route::get('/collections/create', [CollectionController::class, 'create'])->middleware('auth')->name('collections.create');
Route::post('/collections', [CollectionController::class, 'store'])->middleware(['auth', 'throttle:application'])->name('collections.store');
Route::get('/collections/{collection}/edit', [CollectionController::class, 'edit'])->middleware('auth')->name('collections.edit');
Route::put('/collections/{collection}/puzzles', [CollectionController::class, 'syncPuzzles'])->middleware(['auth', 'throttle:application'])->name('collections.puzzles.sync');
Route::put('/collections/{collection}', [CollectionController::class, 'update'])->middleware(['auth', 'throttle:application'])->name('collections.update');
Route::delete('/collections/{collection}', [CollectionController::class, 'destroy'])->middleware(['auth', 'throttle:application'])->name('collections.destroy');
Route::get('/collections/{collection}', [CollectionController::class, 'show'])->middleware('throttle:application')->name('collections.show');
Route::get('/puzzles', PuzzleBrowseController::class)->middleware('throttle:application')->name('puzzles.index');
Route::get('/puzzles/{puzzle}/revisions/{revision}', [PublicPuzzleController::class, 'showRevision'])->middleware('throttle:application')->name('puzzles.revisions.show');
Route::get('/puzzles/{puzzle}/revisions/{revision}/game-session', [PuzzleGameSessionController::class, 'show'])->middleware('throttle:application')->name('puzzles.game-session.show');
Route::post('/puzzles/{puzzle}/revisions/{revision}/game-mode', [PuzzleGameSessionController::class, 'setMode'])->middleware('throttle:application')->name('puzzles.game-session.mode');
Route::post('/puzzles/{puzzle}/revisions/{revision}/life-check', [PuzzleGameSessionController::class, 'checkFill'])->middleware('throttle:application')->name('puzzles.game-session.life-check');
Route::post('/puzzles/{puzzle}/revisions/{revision}/restart', [PuzzleGameSessionController::class, 'restart'])->middleware('throttle:application')->name('puzzles.game-session.restart');
Route::get('/puzzles/{puzzle}', [PublicPuzzleController::class, 'show'])->middleware('throttle:application')->name('puzzles.show');

Route::get('/dashboard', function (Request $request) {
    $showPendingPublicPuzzles = app(DiscoverySettings::class)->showPendingPublicPuzzles();
    $puzzles = $request->user()->puzzles()
        ->with(['publishedRevision' => fn ($query) => $query
            ->withCount('progressRecords')
            ->withCount(['progressRecords as verified_completions_count' => fn ($progress) => $progress->whereNotNull('completed_at')])])
        ->latest('updated_at')->limit(10)->get();
    $recentProgress = $request->user()->progressRecords()
        ->whereHas('revision.puzzle', function ($query) use ($showPendingPublicPuzzles) {
            $query->where('status', 'published')->where(function ($visibility) use ($showPendingPublicPuzzles) {
                $visibility->where(fn ($approved) => $approved->whereIn('visibility', ['public', 'unlisted'])->where('moderation_status', 'approved'));
                if ($showPendingPublicPuzzles) {
                    $visibility->orWhere(fn ($pending) => $pending->where('visibility', 'public')->where('moderation_status', 'pending'));
                }
            });
        })
        ->with('revision.puzzle')
        ->latest('last_played_at')
        ->limit(10)
        ->get();

    return view('dashboard', ['puzzles' => $puzzles, 'recentProgress' => $recentProgress]);
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/moderation', [ModerationController::class, 'index'])->middleware(['verified', 'throttle:application'])->name('moderation.index');
    Route::patch('/moderation/settings', [ModerationController::class, 'updateSettings'])->middleware(['verified', 'throttle:application'])->name('moderation.settings.update');
    Route::post('/moderation/puzzles/{puzzle}', [ModerationController::class, 'update'])->middleware(['verified', 'throttle:application'])->name('moderation.update');

    Route::get('/account/export', AccountDataExportController::class)
        ->middleware(['verified', 'throttle:account-export'])->name('account.export');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('throttle:application')->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('throttle:application')->name('profile.destroy');

    Route::get('/editor/new', fn () => view('editor', ['puzzle' => null]))->name('editor.new');
    Route::get('/editor/import', fn () => view('image-importer'))->name('editor.import');
    Route::get('/editor/{puzzle}', function (Puzzle $puzzle) {
        Gate::authorize('update', $puzzle);

        return view('editor', ['puzzle' => $puzzle]);
    })->name('editor.edit');

    Route::get('/puzzles/{puzzle}/revisions/{revision}/progress', [PuzzleProgressController::class, 'show'])
        ->middleware('throttle:application')->name('puzzles.progress.show');
    Route::put('/puzzles/{puzzle}/revisions/{revision}/progress', [PuzzleProgressController::class, 'save'])
        ->middleware('throttle:application')->name('puzzles.progress.save');
    Route::post('/puzzles/{puzzle}/revisions/{revision}/complete', CompletePuzzleController::class)
        ->middleware('throttle:application')->name('puzzles.progress.complete');
    Route::post('/puzzles/{puzzle}/unpublish', UnpublishPuzzleController::class)
        ->middleware('throttle:application')->name('puzzles.unpublish');

    Route::prefix('puzzles/drafts')->name('puzzles.drafts.')->group(function () {
        Route::post('/', [PuzzleDraftController::class, 'store'])->middleware('throttle:application')->name('store');
        Route::get('/{puzzle}', [PuzzleDraftController::class, 'show'])->middleware('throttle:application')->name('show');
        Route::put('/{puzzle}', [PuzzleDraftController::class, 'update'])->middleware('throttle:application')->name('update');
        Route::post('/{puzzle}/publish', PublishPuzzleController::class)->middleware('throttle:application')->name('publish');
    });
});

require __DIR__.'/auth.php';
