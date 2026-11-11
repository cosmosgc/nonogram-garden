<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_moderator')->default(false)->after('bio');
        });

        Schema::table('puzzles', function (Blueprint $table) {
            $table->string('moderation_status', 20)->default('pending')->after('visibility');
            $table->string('moderation_note', 500)->nullable()->after('moderation_status');
            $table->foreignId('moderated_by')->nullable()->after('published_at')->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
            $table->index(['status', 'moderation_status', 'updated_at'], 'puzzles_moderation_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropIndex('puzzles_moderation_queue_index');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['moderation_status', 'moderation_note', 'moderated_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_moderator');
        });
    }
};
