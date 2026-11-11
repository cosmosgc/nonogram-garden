<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->boolean('is_unique')->nullable()->after('solution_hash');
        });
    }

    public function down(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->dropColumn('is_unique');
        });
    }
};
