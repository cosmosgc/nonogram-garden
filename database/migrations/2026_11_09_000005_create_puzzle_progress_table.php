<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puzzle_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_revision_id')->constrained('puzzle_revisions')->cascadeOnDelete();
            $table->text('marks');
            $table->unsignedInteger('elapsed_seconds')->default(0);
            $table->unsignedInteger('mistake_count')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'puzzle_revision_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puzzle_progress');
    }
};
