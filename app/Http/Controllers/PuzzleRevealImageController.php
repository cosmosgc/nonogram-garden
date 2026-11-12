<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleProgress;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PuzzleRevealImageController extends Controller
{
    public function __invoke(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): BinaryFileResponse
    {
        if ($revision->puzzle_id !== $puzzle->id || ! $settings->canPlayPuzzle($puzzle)
            || $revision->reveal_image_path === null) {
            throw new NotFoundHttpException;
        }

        $completed = PuzzleProgress::query()
            ->where('user_id', $request->user()->id)
            ->where('puzzle_revision_id', $revision->id)
            ->whereNotNull('completed_at')
            ->exists();

        $disk = Storage::disk('local');
        if (! $completed || ! $disk->exists($revision->reveal_image_path)) {
            throw new NotFoundHttpException;
        }

        $response = response()->file($disk->path($revision->reveal_image_path), [
            'Content-Disposition' => 'inline; filename="puzzle-reveal.png"',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
