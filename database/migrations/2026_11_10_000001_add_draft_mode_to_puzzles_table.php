<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->string('draft_mode', 20)->default('classic')->after('draft_version');
            $table->unsignedTinyInteger('draft_rules_version')->default(1)->after('draft_mode');
        });

        DB::table('puzzles')->update(['draft_mode' => 'classic', 'draft_rules_version' => 1]);
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropColumn(['draft_mode', 'draft_rules_version']);
        });
    }
};
