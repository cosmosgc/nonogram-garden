<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'puzzle_revision_id', 'marks', 'elapsed_seconds', 'mistake_count', 'version', 'completed_at', 'last_played_at'])]
class PuzzleProgress extends Model
{
    protected function casts(): array
    {
        return [
            'elapsed_seconds' => 'integer',
            'mistake_count' => 'integer',
            'version' => 'integer',
            'completed_at' => 'datetime',
            'last_played_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(PuzzleRevision::class, 'puzzle_revision_id');
    }
}
