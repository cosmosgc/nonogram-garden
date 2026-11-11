<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 140)->unique();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('visibility', 20)->default('private');
            $table->timestamps();
            $table->index(['user_id', 'visibility', 'updated_at']);
        });

        Schema::create('collection_puzzle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('puzzle_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->unique(['collection_id', 'puzzle_id']);
            $table->unique(['collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_puzzle');
        Schema::dropIfExists('collections');
    }
};
