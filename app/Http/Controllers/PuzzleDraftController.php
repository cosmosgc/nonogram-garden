<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePuzzleDraftRequest;
use App\Http\Requests\UpdatePuzzleDraftRequest;
use App\Models\Puzzle;
use App\Services\Nonogram\ColorGrid;
use App\Services\Nonogram\Grid;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PuzzleDraftController extends Controller
{
    public function store(StorePuzzleDraftRequest $request): JsonResponse
    {
        $data = $request->validated();
        $document = $this->normalizeDocument($data['grid']);
        $grid = $this->grid($document);
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
        $puzzle->save();

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
                'rules_version' => $data['rules_version'] ?? 1,
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'cells' => $data['cells'] ?? null,
                'palette' => $data['palette'] ?? null,
            ];
        }

        return [
            'version' => $data['version'] ?? 1,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
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
