<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->string('mode', 20)->default('classic')->after('height');
            $table->unsignedTinyInteger('rules_version')->default(1)->after('mode');
        });

        DB::table('puzzle_revisions')->update(['mode' => 'classic', 'rules_version' => 1]);
    }

    public function down(): void
    {
        Schema::table('puzzle_revisions', function (Blueprint $table) {
            $table->dropColumn(['mode', 'rules_version']);
        });
    }
};
