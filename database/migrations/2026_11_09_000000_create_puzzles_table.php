<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120)->default('Untitled puzzle');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('width');
            $table->unsignedTinyInteger('height');
            $table->string('status', 20)->default('draft');
            $table->string('visibility', 20)->default('private');
            $table->unsignedInteger('draft_version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'status', 'created_at']);
        });

        Schema::create('puzzle_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->unsignedTinyInteger('width');
            $table->unsignedTinyInteger('height');
            $table->unsignedTinyInteger('format_version')->default(1);
            $table->text('solution_data');
            $table->json('row_clues');
            $table->json('column_clues');
            $table->char('solution_hash', 64);
            $table->string('validation_status', 20)->default('pending');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['puzzle_id', 'revision_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_revisions');
        Schema::dropIfExists('puzzles');
    }
};
