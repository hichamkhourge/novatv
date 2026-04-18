<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link each IPTV account and each channel to a specific M3U source.
 *
 * - iptv_accounts.m3u_source_id → nullable FK → m3u_sources
 * - channels.m3u_source_id      → nullable FK → m3u_sources
 *
 * Nullable so existing data is not broken; assign sources via Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add m3u_source_id to iptv_accounts
        if (! Schema::hasColumn('iptv_accounts', 'm3u_source_id')) {
            Schema::table('iptv_accounts', function (Blueprint $table) {
                $table->foreignId('m3u_source_id')
                    ->nullable()
                    ->after('notes')
                    ->constrained('m3u_sources')
                    ->nullOnDelete();
            });
        }

        // Add m3u_source_id to channels
        if (! Schema::hasColumn('channels', 'm3u_source_id')) {
            Schema::table('channels', function (Blueprint $table) {
                $table->foreignId('m3u_source_id')
                    ->nullable()
                    ->after('channel_group_id')
                    ->constrained('m3u_sources')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->dropForeign(['m3u_source_id']);
            $table->dropColumn('m3u_source_id');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['m3u_source_id']);
            $table->dropColumn('m3u_source_id');
        });
    }
};
