<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Disable FK checks (PostgreSQL)
        DB::statement('SET session_replication_role = replica;');

        DB::table('channels')->truncate();
        DB::table('channel_groups')->truncate();

        // Reset all source counts
        DB::table('m3u_sources')->update([
            'channels_count' => 0,
            'status'         => 'idle',
            'last_synced_at' => null,
        ]);

        DB::statement('SET session_replication_role = DEFAULT;');
    }

    public function down(): void
    {
        // Irreversible
    }
};
