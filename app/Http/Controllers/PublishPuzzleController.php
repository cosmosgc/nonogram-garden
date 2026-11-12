<?php

namespace App\Http\Controllers;

use App\Http\Requests\PublishPuzzleRequest;
use App\Models\Puzzle;
use App\Services\Nonogram\Rulesets\RulesetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class PublishPuzzleController extends Controller
{
    public function __invoke(PublishPuzzleRequest $request, Puzzle $puzzle, RulesetRegistry $rulesets): JsonResponse
    {
        return DB::transaction(function () use ($request, $puzzle, $rulesets): JsonResponse {
            $locked = Puzzle::query()->lockForUpdate()->findOrFail($puzzle->getKey());
            Gate::authorize('publish', $locked);

            $mode = (string) ($locked->draft_mode ?? 'classic');
            $rulesVersion = (int) ($locked->draft_rules_version ?? 1);
            try {
                $ruleset = $rulesets->resolve($mode, $rulesVersion);
            } catch (InvalidArgumentException) {
                return response()->json([
                    'message' => 'This puzzle mode or rules version is not supported.',
                    'mode' => $mode,
                    'rules_version' => $rulesVersion,
                ], 422);
            }

            $document = [
                'version' => 1,
                'mode' => $mode,
                'rules_version' => $rulesVersion,
                'width' => $locked->width,
                'height' => $locked->height,
                'cells' => $locked->draft_cells,
                'palette' => $locked->draft_palette,
            ];
            if (! $ruleset->supportsDocument($document)) {
                return response()->json(['message' => 'The puzzle cell encoding is not supported by this rules version.'], 422);
            }

            try {
                $cells = $ruleset->canonicalCells($document, $locked->width, $locked->height);
                $clues = $ruleset->clues($cells, $locked->width, $locked->height, $locked->draft_palette);
            } catch (InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            $result = $ruleset->analyze($locked->width, $locked->height, $clues['rows'], $clues['columns'], $locked->draft_palette);
            if ($result !== 'unique') {
                return response()->json([
                    'message' => $result === 'ambiguous'
                        ? 'This puzzle has multiple valid solutions. Edit the grid or clues before publishing.'
                        : 'The puzzle uniqueness check could not verify a unique solution.',
                    'uniqueness' => $result,
                ], 422);
            }

            $revisionNumber = ((int) $locked->revisions()->max('revision_number')) + 1;
            $revision = $locked->revisions()->create([
                'revision_number' => $revisionNumber,
                'width' => $locked->width,
                'height' => $locked->height,
                'mode' => $ruleset->id(),
                'rules_version' => $ruleset->version(),
                'format_version' => 1,
                'solution_data' => $cells,
                'palette' => $locked->draft_palette,
                'row_clues' => $clues['rows'],
                'column_clues' => $clues['columns'],
                'solution_hash' => $ruleset->hash($cells, $locked->width, $locked->height, $locked->draft_palette),
                'original_image_path' => $locked->draft_original_image_path,
                'reveal_image_path' => $locked->draft_reveal_image_path,
                'is_unique' => true,
                'validation_status' => 'unique',
                'created_by' => $request->user()->id,
            ]);

            $locked->fill([
                'status' => 'published',
                'visibility' => 'public',
                'published_revision_id' => $revision->id,
                'published_at' => now(),
            ])->forceFill([
                'moderation_status' => 'pending',
                'moderation_note' => null,
                'moderated_by' => null,
                'moderated_at' => null,
            ])->save();

            return response()->json([
                'id' => $locked->id,
                'revision_id' => $revision->id,
                'revision_number' => $revision->revision_number,
                'status' => $locked->status,
                'moderation_status' => $locked->moderation_status,
                'uniqueness' => $result,
            ], 201);
        });
    }
}
