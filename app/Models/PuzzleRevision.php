<?php

namespace App\Models;

use App\Services\Nonogram\Rulesets\ClassicRuleset;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['puzzle_id', 'revision_number', 'width', 'height', 'mode', 'rules_version', 'format_version', 'solution_data', 'palette', 'row_clues', 'column_clues', 'solution_hash', 'original_image_path', 'reveal_image_path', 'is_unique', 'validation_status', 'created_by'])]
class PuzzleRevision extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Puzzle revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Puzzle revisions are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'palette' => 'array',
            'row_clues' => 'array',
            'column_clues' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'rules_version' => 'integer',
            'is_unique' => 'boolean',
        ];
    }

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function progressRecords(): HasMany
    {
        return $this->hasMany(PuzzleProgress::class, 'puzzle_revision_id');
    }

    public function isClassic(): bool
    {
        return $this->mode === ClassicRuleset::MODE && $this->rules_version === ClassicRuleset::VERSION;
    }
}
