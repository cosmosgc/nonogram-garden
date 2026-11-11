<?php

namespace Database\Factories;

use App\Models\Puzzle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Puzzle> */
class PuzzleFactory extends Factory
{
    protected $model = Puzzle::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'width' => 5,
            'height' => 5,
            'status' => 'draft',
            'visibility' => 'private',
            'draft_version' => 1,
            'draft_cells' => str_repeat('0', 25),
            'draft_palette' => null,
            'draft_mode' => 'classic',
            'draft_rules_version' => 1,
        ];
    }
}
