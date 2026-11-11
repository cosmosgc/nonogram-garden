<?php

namespace Tests\Feature;

use App\Models\Puzzle;
use App\Models\PuzzleProgress;
use App\Models\PuzzleRevision;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    private string $testDirectory = '';

    private string $databasePath = '';

    private string $mediaPath = '';

    private string $originalDefaultConnection = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = (string) config('database.default');
        $this->testDirectory = storage_path('framework/testing/backup-restore-'.Str::uuid());
        $this->databasePath = $this->testDirectory.'/live/database.sqlite';
        $this->mediaPath = $this->testDirectory.'/live/media';

        File::ensureDirectoryExists(dirname($this->databasePath));
        File::ensureDirectoryExists($this->mediaPath);
        File::put($this->databasePath, '');

        config([
            'database.default' => 'backup_restore',
            'database.connections.backup_restore' => array_merge(config('database.connections.sqlite'), [
                'url' => null,
                'database' => $this->databasePath,
                'foreign_key_constraints' => true,
            ]),
        ]);
        DB::purge('backup_restore');

        $this->assertSame(0, Artisan::call('migrate:fresh', [
            '--database' => 'backup_restore',
            '--force' => true,
        ]));
    }

    protected function tearDown(): void
    {
        DB::purge('backup_restore');
        config(['database.default' => $this->originalDefaultConnection]);

        if ($this->testDirectory !== '') {
            File::deleteDirectory($this->testDirectory);
        }

        parent::tearDown();
    }

    public function test_database_and_media_backup_restores_puzzle_revision_progress_and_avatar(): void
    {
        $owner = User::factory()->create();
        $puzzle = Puzzle::factory()->create([
            'user_id' => $owner->id,
            'width' => 1,
            'height' => 1,
            'draft_cells' => '1',
        ]);
        $this->publishAndApprove($puzzle, $owner)->assertCreated();

        $revision = $puzzle->fresh()->publishedRevision;
        $player = User::factory()->create();
        $this->actingAs($player)->putJson(route('puzzles.progress.save', [
            'puzzle' => $puzzle->id,
            'revision' => $revision->id,
        ]), [
            'marks' => '1',
            'elapsed_seconds' => 9,
            'version' => 0,
        ])->assertOk()->assertJsonPath('progress.marks', '1');

        $mediaRelativePath = "avatars/{$owner->id}/restore-check.png";
        $mediaContents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXioAAAAASUVORK5CYII=', true);
        $owner->forceFill(['avatar_path' => $mediaRelativePath])->save();
        File::ensureDirectoryExists(dirname($this->mediaPath.'/'.$mediaRelativePath));
        File::put($this->mediaPath.'/'.$mediaRelativePath, $mediaContents);

        $backupDirectory = $this->testDirectory.'/backup';
        $backupDatabasePath = $backupDirectory.'/database.sqlite';
        $backupMediaPath = $backupDirectory.'/media';
        File::ensureDirectoryExists($backupDirectory);

        DB::purge('backup_restore');
        $this->assertTrue(File::copy($this->databasePath, $backupDatabasePath));
        $this->assertTrue(File::copyDirectory($this->mediaPath, $backupMediaPath));

        DB::purge('backup_restore');
        File::delete($this->databasePath);
        File::delete($this->databasePath.'-wal');
        File::delete($this->databasePath.'-shm');
        File::deleteDirectory($this->mediaPath);

        $this->assertFalse(File::exists($this->databasePath));
        $this->assertFalse(File::isDirectory($this->mediaPath));

        $this->assertTrue(File::copy($backupDatabasePath, $this->databasePath));
        $this->assertTrue(File::copyDirectory($backupMediaPath, $this->mediaPath));
        DB::reconnect('backup_restore');

        $restoredPuzzle = Puzzle::query()->with('publishedRevision')->findOrFail($puzzle->id);
        $restoredRevision = PuzzleRevision::query()
            ->where('puzzle_id', $puzzle->id)
            ->where('revision_number', 1)
            ->firstOrFail();
        $restoredProgress = PuzzleProgress::query()
            ->where('user_id', $player->id)
            ->where('puzzle_revision_id', $revision->id)
            ->firstOrFail();

        $this->assertSame($revision->id, $restoredPuzzle->publishedRevision->id);
        $this->assertSame('1', $restoredRevision->solution_data);
        $this->assertSame($restoredRevision->id, $restoredProgress->revision->id);
        $this->assertSame($puzzle->id, $restoredProgress->revision->puzzle->id);
        $this->assertSame('1', $restoredProgress->marks);
        $this->assertNotNull($restoredProgress->completed_at);
        $this->assertSame($mediaRelativePath, $owner->fresh()->avatar_path);
        $this->assertSame($mediaContents, File::get($this->mediaPath.'/'.$mediaRelativePath));
    }
}
