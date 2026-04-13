<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Housekeeping migration: drop all legacy tables so the old migration chain
 * can still run on a fresh database without FK constraint failures, and so
 * our new migrations have a clean slate.
 *
 * This migration is safe to run even if the tables don't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Disable FK checks for the cleanup
        DB::statement('SET session_replication_role = replica;'); // PostgreSQL equivalent of disabling FK checks

        $legacyTables = [
            'user_sources',
            'user_automation_logs',
            'iptv_user_channel',
            'stream_sessions',
            'connection_logs',
            'tuliprox_servers',
        ];

        foreach ($legacyTables as $table) {
            Schema::dropIfExists($table);
        }

        DB::statement('SET session_replication_role = DEFAULT;');
    }

    public function down(): void
    {
        // Nothing to reverse — these tables are gone
    }
};
