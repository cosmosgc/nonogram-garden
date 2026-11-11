<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->index(['status', 'visibility', 'published_at'], 'puzzles_publication_listing_index');
            $table->index(['user_id', 'status', 'visibility', 'published_at'], 'puzzles_creator_listing_index');
        });

        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->index(['width', 'height'], 'puzzle_revisions_dimensions_index');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->index(['visibility', 'updated_at'], 'collections_public_listing_index');
        });

        Schema::table('puzzle_progress', function (Blueprint $table) {
            $table->index(['user_id', 'last_played_at'], 'puzzle_progress_recent_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_progress', function (Blueprint $table) {
            $table->dropIndex('puzzle_progress_recent_user_index');
        });

        Schema::table('collections', function (Blueprint $table) {
            $table->dropIndex('collections_public_listing_index');
        });

        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->dropIndex('puzzle_revisions_dimensions_index');
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropIndex('puzzles_creator_listing_index');
            $table->dropIndex('puzzles_publication_listing_index');
        });
    }
};
