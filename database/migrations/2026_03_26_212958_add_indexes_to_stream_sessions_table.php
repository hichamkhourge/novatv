<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stream_sessions', function (Blueprint $table) {
            // Composite index for queries filtering by user and last_seen_at
            // Used by ConnectionTrackerService to count active connections
            $table->index(['iptv_user_id', 'last_seen_at'], 'idx_user_lastseen');

            // Index for cleanup queries that filter only by last_seen_at
            // Used to find and delete stale sessions
            $table->index('last_seen_at', 'idx_lastseen');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_sessions', function (Blueprint $table) {
            $table->dropIndex('idx_user_lastseen');
            $table->dropIndex('idx_lastseen');
        });
    }
};
