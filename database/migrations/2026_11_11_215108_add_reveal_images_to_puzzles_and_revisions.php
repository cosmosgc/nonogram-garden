<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->string('draft_original_image_path')->nullable();
            $table->string('draft_reveal_image_path')->nullable();
        });

        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->string('original_image_path')->nullable();
            $table->string('reveal_image_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->dropColumn(['original_image_path', 'reveal_image_path']);
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropColumn(['draft_original_image_path', 'draft_reveal_image_path']);
        });
    }
};
