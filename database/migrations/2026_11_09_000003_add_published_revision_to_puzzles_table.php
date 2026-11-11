<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->unsignedBigInteger('published_revision_id')->nullable()->after('draft_version');
            $table->timestamp('published_at')->nullable()->after('published_revision_id');
            $table->foreign('published_revision_id')->references('id')->on('puzzle_revisions')->nullOnDelete();
            $table->index(['status', 'visibility', 'published_at'], 'puzzles_discovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropIndex('puzzles_discovery_index');
            $table->dropForeign(['published_revision_id']);
            $table->dropColumn(['published_revision_id', 'published_at']);
        });
    }
};
