<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePuzzleDraftRequest;
use App\Http\Requests\UpdatePuzzleDraftRequest;
use App\Models\Puzzle;
use App\Services\Nonogram\ColorGrid;
use App\Services\Nonogram\Grid;
use App\Services\Nonogram\PuzzleImageStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class PuzzleDraftController extends Controller
{
    public function store(StorePuzzleDraftRequest $request, PuzzleImageStorage $imageStorage): JsonResponse
    {
        $data = $request->validated();
        $document = $this->normalizeDocument($data['grid']);
        $grid = $this->grid($document);
        $sourceImage = $data['source_image'] ?? null;
        $revealImage = $data['reveal_image'] ?? null;
        unset($data['source_image'], $data['reveal_image']);
        $puzzle = new Puzzle([
            'title' => $data['title'] ?? 'Untitled puzzle',
            'description' => $data['description'] ?? null,
            'tags' => $this->normalizeTags($data['tags'] ?? []),
            'width' => $grid->width,
            'height' => $grid->height,
            'draft_cells' => $grid->cells,
            'draft_palette' => $grid instanceof ColorGrid ? $grid->palette : null,
            'draft_mode' => $grid instanceof ColorGrid ? 'color' : 'classic',
            'draft_rules_version' => 1,
            'status' => 'draft',
            'visibility' => 'private',
            'draft_version' => 1,
        ]);
        $puzzle->owner()->associate($request->user());

        $puzzleId = null;
        try {
            $puzzle = DB::transaction(function () use ($puzzle, $sourceImage, $revealImage, $imageStorage, &$puzzleId): Puzzle {
                $puzzle->save();
                $puzzleId = $puzzle->id;

                if ($sourceImage instanceof UploadedFile && $revealImage instanceof UploadedFile) {
                    $puzzle->forceFill($imageStorage->store($puzzle, $sourceImage, $revealImage))->save();
                }

                return $puzzle;
            });
        } catch (Throwable $exception) {
            if ($puzzleId !== null) {
                $imageStorage->deleteForPuzzle($puzzleId);
            }

            throw $exception;
        }

        return response()->json($this->payload($puzzle, $grid), 201);
    }

    public function show(Puzzle $puzzle): JsonResponse
    {
        Gate::authorize('view', $puzzle);
        $grid = $this->draftGrid($puzzle);

        return response()->json($this->payload($puzzle, $grid));
    }

    public function update(UpdatePuzzleDraftRequest $request, Puzzle $puzzle): JsonResponse
    {
        $data = $request->validated();
        $document = $this->normalizeDocument($data['grid']);
        $grid = $this->grid($document);

        return DB::transaction(function () use ($puzzle, $data, $grid): JsonResponse {
            // Re-read and lock inside the transaction so two saves with the same
            // version cannot both succeed on databases with row-level locking.
            $locked = Puzzle::query()->lockForUpdate()->findOrFail($puzzle->getKey());
            Gate::authorize('update', $locked);

            if ((int) $data['version'] !== $locked->draft_version) {
                return response()->json([
                    'message' => 'Draft changed elsewhere. Reload or recover your local edits.',
                    'current_version' => $locked->draft_version,
                ], 409);
            }

            $locked->fill([
                'title' => $data['title'] ?? $locked->title,
                'description' => array_key_exists('description', $data) ? $data['description'] : $locked->description,
                'tags' => array_key_exists('tags', $data) ? $this->normalizeTags($data['tags']) : ($locked->tags ?? []),
                'width' => $grid->width,
                'height' => $grid->height,
                'draft_cells' => $grid->cells,
                'draft_palette' => $grid instanceof ColorGrid ? $grid->palette : null,
                'draft_mode' => $grid instanceof ColorGrid ? 'color' : 'classic',
                'draft_rules_version' => 1,
                'draft_version' => $locked->draft_version + 1,
            ])->save();

            return response()->json($this->payload($locked, $grid));
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeDocument(array $data): array
    {
        if (($data['mode'] ?? 'classic') === 'color') {
            return [
                'mode' => 'color',
                'rules_version' => (int) ($data['rules_version'] ?? 1),
                'width' => isset($data['width']) ? (int) $data['width'] : null,
                'height' => isset($data['height']) ? (int) $data['height'] : null,
                'cells' => $data['cells'] ?? null,
                'palette' => $data['palette'] ?? null,
            ];
        }

        return [
            'version' => (int) ($data['version'] ?? 1),
            'width' => isset($data['width']) ? (int) $data['width'] : null,
            'height' => isset($data['height']) ? (int) $data['height'] : null,
            'cells' => $data['cells'] ?? null,
        ];
    }

    private function draftGrid(Puzzle $puzzle): Grid|ColorGrid
    {
        if ($puzzle->draft_mode === 'color') {
            return $this->grid([
                'mode' => $puzzle->draft_mode,
                'rules_version' => $puzzle->draft_rules_version,
                'width' => $puzzle->width,
                'height' => $puzzle->height,
                'cells' => $puzzle->draft_cells,
                'palette' => $puzzle->draft_palette,
            ]);
        }

        return $this->grid(['version' => 1, 'width' => $puzzle->width, 'height' => $puzzle->height, 'cells' => $puzzle->draft_cells]);
    }

    /** @param array<string, mixed> $data */
    private function grid(array $data): Grid|ColorGrid
    {
        try {
            if (($data['mode'] ?? 'classic') === 'color') {
                return ColorGrid::fromArray($data);
            }

            return Grid::fromArray($data);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json([
                'message' => $exception->getMessage(),
                'errors' => ['grid' => [$exception->getMessage()]],
            ], 422));
        }
    }

    /** @param list<string> $tags @return list<string> */
    private function normalizeTags(array $tags): array
    {
        return array_values(array_unique($tags));
    }

    private function payload(Puzzle $puzzle, Grid|ColorGrid $grid): array
    {
        $document = $grid->toArray();
        $clues = $grid->clues();

        return [
            'id' => $puzzle->id,
            'title' => $puzzle->title,
            'description' => $puzzle->description,
            'tags' => $puzzle->tags ?? [],
            'version' => $puzzle->draft_version,
            'updated_at' => $puzzle->updated_at?->toIso8601String(),
            'grid' => $document,
            'clues' => $clues,
            'hash' => $grid->hash(),
        ];
    }
}
