<?php

namespace App\Services\Nonogram;

use App\Models\Puzzle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PuzzleImageStorage
{
    public function __construct(private PuzzleImageProcessor $processor) {}

    /** @return array{draft_original_image_path: string, draft_reveal_image_path: string} */
    public function store(Puzzle $puzzle, UploadedFile $sourceImage, UploadedFile $revealImage): array
    {
        $directory = "puzzle-images/{$puzzle->id}";
        $identifier = (string) Str::uuid();
        $originalPath = "{$directory}/{$identifier}-original.".$this->processor->sourceExtension($sourceImage);
        $revealPath = "{$directory}/{$identifier}-reveal.png";
        $disk = Storage::disk('local');
        $originalContents = file_get_contents($sourceImage->getPathname());
        if (! is_string($originalContents)) {
            throw new RuntimeException('The original image could not be read.');
        }

        try {
            if (! $disk->put($originalPath, $originalContents)) {
                throw new RuntimeException('The original image could not be stored.');
            }

            if (! $disk->put($revealPath, $this->processor->toCleanPng($revealImage))) {
                throw new RuntimeException('The reveal image could not be stored.');
            }
        } catch (\Throwable $exception) {
            $disk->delete([$originalPath, $revealPath]);
            throw $exception;
        }

        return [
            'draft_original_image_path' => $originalPath,
            'draft_reveal_image_path' => $revealPath,
        ];
    }

    public function deleteForPuzzle(int $puzzleId): void
    {
        Storage::disk('local')->deleteDirectory("puzzle-images/{$puzzleId}");
    }
}
