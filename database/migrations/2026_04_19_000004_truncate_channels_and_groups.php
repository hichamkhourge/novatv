<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Disable foreign key checks so we can truncate without constraint errors
        DB::statement('SET session_replication_role = replica;'); // PostgreSQL

        DB::table('channels')->truncate();
        DB::table('channel_groups')->truncate();

        // Also reset channels_count on all m3u_sources
        DB::table('m3u_sources')->update([
            'channels_count' => 0,
            'status'         => 'idle',
            'last_synced_at' => null,
        ]);

        DB::statement('SET session_replication_role = DEFAULT;'); // restore FK checks
    }

    public function down(): void
    {
        // Irreversible — data is gone
    }
};
