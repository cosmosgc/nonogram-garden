<?php

namespace App\Http\Requests;

use App\Models\PuzzleRevision;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SavePuzzleProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $revision = $this->route('revision');
        $cellCount = $revision instanceof PuzzleRevision && $revision->width <= 30 && $revision->height <= 30
            ? $revision->width * $revision->height
            : 0;

        $isColor = $revision instanceof PuzzleRevision && $revision->mode === 'color' && $revision->rules_version === 1;

        return [
            'marks' => [
                'required',
                'string',
                'size:'.$cellCount,
                $isColor ? 'regex:/\A[0-9]+\z/' : 'regex:/\A[012]+\z/',
                function (string $attribute, mixed $value, Closure $fail) use ($revision, $isColor): void {
                    if (! $isColor || ! $revision instanceof PuzzleRevision || ! is_string($value)) {
                        return;
                    }

                    $paletteSize = is_array($revision->palette) ? count($revision->palette) : 0;
                    foreach (str_split($value) as $mark) {
                        if ($mark !== '0' && $mark !== '9' && (int) $mark > $paletteSize) {
                            $fail('The marks field contains a colour that is not in this puzzle palette.');

                            return;
                        }
                    }
                },
            ],
            'elapsed_seconds' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'version' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'mistake_count' => ['prohibited'],
            'completed_at' => ['prohibited'],
            'completed' => ['prohibited'],
            'user_id' => ['prohibited'],
            'puzzle_revision_id' => ['prohibited'],
        ];
    }
}
