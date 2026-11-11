<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['user_id', 'slug', 'title', 'description', 'visibility'])]
class Collection extends Model
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function puzzles(): BelongsToMany
    {
        return $this->belongsToMany(Puzzle::class)
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }
}
