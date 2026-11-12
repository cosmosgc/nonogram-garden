<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'description', 'tags', 'width', 'height', 'status', 'visibility', 'draft_version', 'draft_cells', 'draft_palette', 'draft_mode', 'draft_rules_version', 'draft_original_image_path', 'draft_reveal_image_path', 'published_revision_id', 'published_at'])]
class Puzzle extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return ['width' => 'integer', 'height' => 'integer', 'draft_version' => 'integer', 'published_at' => 'datetime', 'moderated_at' => 'datetime', 'tags' => 'array', 'draft_palette' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(PuzzleRevision::class);
    }

    public function publishedRevision(): BelongsTo
    {
        return $this->belongsTo(PuzzleRevision::class, 'published_revision_id');
    }
}
