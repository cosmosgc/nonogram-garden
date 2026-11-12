<?php

namespace App\Http\Controllers;

use App\Models\Puzzle;
use App\Models\PuzzleRevision;
use App\Services\Nonogram\DiscoverySettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PuzzleGameSessionController extends Controller
{
    public function show(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): JsonResponse
    {
        $this->ensurePlayable($puzzle, $revision, $settings);
        $key = $this->key($revision);
        $state = $request->session()->get($key, $this->initialState($revision, false));
        $request->session()->put($key, $state);

        return response()->json($this->publicState($state));
    }

    public function setMode(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): JsonResponse
    {
        $this->ensurePlayable($puzzle, $revision, $settings);
        $hardcore = (bool) $request->validate(['hardcore' => ['required', 'boolean']])['hardcore'];
        $key = $this->key($revision);
        $state = $request->session()->get($key, $this->initialState($revision, false));
        if ($state['mode_locked'] || $state['game_over']) {
            return response()->json(['message' => 'The game mode is locked for this attempt.'] + $this->publicState($state), 409);
        }

        $state = $this->initialState($revision, $hardcore);
        $request->session()->put($key, $state);

        return response()->json($this->publicState($state));
    }

    public function checkFill(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): JsonResponse
    {
        $this->ensurePlayable($puzzle, $revision, $settings);
        $validated = $request->validate([
            'cell_index' => ['required', 'integer', 'min:0', 'max:'.($revision->width * $revision->height - 1)],
            'guess_id' => ['required', 'uuid'],
            'mark' => $revision->mode === 'color'
                ? ['required', 'integer', 'min:1', 'max:'.count($revision->palette ?? [])]
                : ['prohibited'],
        ]);
        $cellIndex = (int) $validated['cell_index'];
        $guessId = (string) $validated['guess_id'];
        $key = $this->key($revision);
        $state = $request->session()->get($key, $this->initialState($revision, false));
        $this->processGuess($state, $revision, $cellIndex, $guessId, isset($validated['mark']) ? (int) $validated['mark'] : null);
        $request->session()->put($key, $state);

        if ($state['game_over'] && ! in_array($guessId, $state['processed_guesses'], true)) {
            return response()->json(['message' => 'No lives remain. Start a new attempt to continue.'] + $this->publicState($state), 409);
        }

        return response()->json($this->publicState($state));
    }

    public function checkFills(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): JsonResponse
    {
        $this->ensurePlayable($puzzle, $revision, $settings);
        $validated = $request->validate([
            'guesses' => ['required', 'array', 'min:1', 'max:200'],
            'guesses.*' => ['required', 'array:cell_index,guess_id,mark'],
            'guesses.*.cell_index' => ['required', 'integer', 'min:0', 'max:'.($revision->width * $revision->height - 1)],
            'guesses.*.guess_id' => ['required', 'uuid', 'distinct'],
            'guesses.*.mark' => $revision->mode === 'color'
                ? ['required', 'integer', 'min:1', 'max:'.count($revision->palette ?? [])]
                : ['prohibited'],
        ]);
        $key = $this->key($revision);
        $state = $request->session()->get($key, $this->initialState($revision, false));
        $processedGuessIds = [];
        $correctGuessIds = [];

        foreach ($validated['guesses'] as $guess) {
            $guessId = (string) $guess['guess_id'];
            $result = $this->processGuess(
                $state,
                $revision,
                (int) $guess['cell_index'],
                $guessId,
                isset($guess['mark']) ? (int) $guess['mark'] : null,
            );

            if ($result === null && $state['game_over']) {
                break;
            }

            $processedGuessIds[] = $guessId;
            if ($result === true && ! $state['hardcore']) {
                $correctGuessIds[] = $guessId;
            }
        }

        $request->session()->put($key, $state);

        return response()->json($this->publicState($state) + [
            'processed_guess_ids' => $processedGuessIds,
            'correct_guess_ids' => $correctGuessIds,
        ]);
    }

    /** @param array<string, mixed> $state */
    private function processGuess(array &$state, PuzzleRevision $revision, int $cellIndex, string $guessId, ?int $mark): ?bool
    {
        $state['guess_results'] ??= [];
        if (isset($state['guess_results'][$guessId])) {
            return $state['guess_results'][$guessId];
        }
        if (in_array($guessId, $state['processed_guesses'], true)) {
            return null;
        }
        if ($state['game_over']) {
            return null;
        }

        $correct = $revision->mode === 'color'
            ? (string) $mark === $revision->solution_data[$cellIndex]
            : $revision->solution_data[$cellIndex] === '1';
        if (! $state['hardcore'] && ! $correct) {
            $state['lives_remaining'] = max(0, $state['lives_remaining'] - 1);
            $state['game_over'] = $state['lives_remaining'] === 0;
        }
        $state['mode_locked'] = true;
        $state['guess_count']++;
        $state['processed_guesses'][] = $guessId;
        $state['processed_guesses'] = array_slice($state['processed_guesses'], -500);
        $state['guess_results'][$guessId] = $correct;
        if (count($state['guess_results']) > 500) {
            $state['guess_results'] = array_slice($state['guess_results'], -500, null, true);
        }

        return $correct;
    }

    public function restart(Request $request, Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): JsonResponse
    {
        $this->ensurePlayable($puzzle, $revision, $settings);
        $key = $this->key($revision);
        $state = $request->session()->get($key, $this->initialState($revision, false));
        if (! $state['game_over']) {
            return response()->json(['message' => 'A new attempt is available after all lives are lost.'] + $this->publicState($state), 409);
        }

        $state = $this->initialState($revision, $state['hardcore']);
        $request->session()->put($key, $state);

        return response()->json($this->publicState($state));
    }

    /** @return array{hardcore:bool,lives_remaining:?int,initial_lives:?int,mode_locked:bool,game_over:bool,guess_count:int,processed_guesses:list<string>,guess_results:array<string,bool>} */
    private function initialState(PuzzleRevision $revision, bool $hardcore): array
    {
        $lives = $hardcore ? null : $this->livesForSize($revision->width * $revision->height);

        return [
            'hardcore' => $hardcore,
            'lives_remaining' => $lives,
            'initial_lives' => $lives,
            'mode_locked' => false,
            'game_over' => false,
            'guess_count' => 0,
            'processed_guesses' => [],
            'guess_results' => [],
        ];
    }

    /** @param array<string, mixed> $state
     * @return array{hardcore:bool,lives_remaining:?int,initial_lives:?int,mode_locked:bool,game_over:bool,guess_count:int}
     */
    private function publicState(array $state): array
    {
        return [
            'hardcore' => $state['hardcore'],
            'lives_remaining' => $state['lives_remaining'],
            'initial_lives' => $state['initial_lives'],
            'mode_locked' => $state['mode_locked'],
            'game_over' => $state['game_over'],
            'guess_count' => $state['guess_count'],
        ];
    }

    private function livesForSize(int $cellCount): int
    {
        return $cellCount <= 100 ? 3 : ($cellCount <= 400 ? 5 : 7);
    }

    private function key(PuzzleRevision $revision): string
    {
        return "nonogram:game-session:{$revision->puzzle_id}:{$revision->id}";
    }

    private function ensurePlayable(Puzzle $puzzle, PuzzleRevision $revision, DiscoverySettings $settings): void
    {
        if ($revision->puzzle_id !== $puzzle->id || $puzzle->status !== 'published'
            || ! $settings->canPlayPuzzle($puzzle) || $revision->width < 1 || $revision->height < 1
            || $revision->width > 30 || $revision->height > 30) {
            throw new NotFoundHttpException;
        }
    }
}
