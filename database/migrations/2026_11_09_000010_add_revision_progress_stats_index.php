<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_progress', function (Blueprint $table) {
            $table->index(['puzzle_revision_id', 'completed_at'], 'progress_revision_completion_stats_index');
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_progress', function (Blueprint $table) {
            $table->dropIndex('progress_revision_completion_stats_index');
        });
    }
};
