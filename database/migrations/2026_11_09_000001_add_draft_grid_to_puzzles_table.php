<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzles', fn (Blueprint $table) => $table->text('draft_cells')->nullable());
    }

    public function down(): void
    {
        Schema::table('puzzles', fn (Blueprint $table) => $table->dropColumn('draft_cells'));
    }
};
