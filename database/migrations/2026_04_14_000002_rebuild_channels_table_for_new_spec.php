<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rebuilds the channels table to match the new spec.
 *
 * The original channels table (2026_03_27) had:
 *   id, name, tvg_id, tvg_name, tvg_logo, group_name, stream_url, m3u_source_id, is_active, timestamps
 *
 * Target schema:
 *   id, channel_group_id (FK), name, stream_url (unique), logo_url, tvg_id, tvg_name, sort_order, is_active, timestamps
 *
 * Steps:
 *  1. Truncate all data (user decision)
 *  2. Drop obsolete columns and FKs
 *  3. Add/rename columns to match spec
 */
return new class extends Migration
{
    public function up(): void
    {
        // Disable FK checks (PostgreSQL)
        DB::statement('SET session_replication_role = replica;');

        // Wipe all existing channel data per user decision
        DB::table('channels')->truncate();

        Schema::table('channels', function (Blueprint $table) {
            $existing = Schema::getColumnListing('channels');

            // Drop old FK constraint on m3u_source_id
            try {
                $table->dropForeign(['m3u_source_id']);
            } catch (\Throwable) {}

            // Drop old unused columns
            $toDrop = array_intersect(
                $existing,
                ['m3u_source_id', 'tvg_logo', 'group_name', 'stream_id', 'logo', 'category', 'epg_id', 'deleted_at']
            );

            if (! empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });

        Schema::table('channels', function (Blueprint $table) {
            $existing = Schema::getColumnListing('channels');

            // Add channel_group_id FK
            if (! in_array('channel_group_id', $existing)) {
                $table->foreignId('channel_group_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('channel_groups')
                    ->nullOnDelete();
            }

            // Add logo_url (replaces tvg_logo)
            if (! in_array('logo_url', $existing)) {
                $table->string('logo_url', 2048)->nullable()->after('stream_url');
            }

            // Add sort_order
            if (! in_array('sort_order', $existing)) {
                $table->unsignedInteger('sort_order')->default(0);
            }

            // Ensure stream_url has unique constraint
            try {
                $table->unique('stream_url');
            } catch (\Throwable) {
                // Already unique
            }

            // Ensure is_active exists
            if (! in_array('is_active', $existing)) {
                $table->boolean('is_active')->default(true);
            }
        });

        DB::statement('SET session_replication_role = DEFAULT;');
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            try { $table->dropUnique(['stream_url']); } catch (\Throwable) {}
            try { $table->dropForeign(['channel_group_id']); } catch (\Throwable) {}

            $existing = Schema::getColumnListing('channels');
            $toDrop = array_intersect($existing, ['channel_group_id', 'logo_url', 'sort_order']);
            if (! empty($toDrop)) {
                $table->dropColumn($toDrop);
            }
        });
    }
};
