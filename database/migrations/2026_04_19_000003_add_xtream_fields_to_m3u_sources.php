<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            // Xtream Codes API credentials
            $table->string('xtream_host')->nullable()->after('url');
            $table->string('xtream_username')->nullable()->after('xtream_host');
            $table->string('xtream_password')->nullable()->after('xtream_username');

            // Which stream types to import: live, vod, series (comma-separated or JSON)
            $table->json('xtream_stream_types')->nullable()->after('xtream_password');

            // Groups to exclude from import (JSON array of group names)
            $table->json('excluded_groups')->nullable()->after('xtream_stream_types');
        });

        // Update existing 'url' source_type rows to be explicit
        DB::statement("UPDATE m3u_sources SET source_type = 'url' WHERE source_type IS NULL OR source_type = ''");
    }

    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->dropColumn([
                'xtream_host',
                'xtream_username',
                'xtream_password',
                'xtream_stream_types',
                'excluded_groups',
            ]);
        });
    }
};
