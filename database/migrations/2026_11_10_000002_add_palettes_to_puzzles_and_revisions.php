<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->json('palette')->nullable()->after('solution_data');
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->json('draft_palette')->nullable()->after('draft_cells');
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->dropColumn('palette');
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropColumn('draft_palette');
        });
    }
};
