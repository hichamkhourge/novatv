<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-adds source_type and file_path to m3u_sources.
 *
 * These columns were dropped in the 2026_03_30 refactor migration
 * but are required for the file-upload feature.
 * Also makes url nullable so file-based sources don't need a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            // Re-add source_type (url or file)
            if (! Schema::hasColumn('m3u_sources', 'source_type')) {
                $table->enum('source_type', ['url', 'file'])
                    ->default('url')
                    ->after('name');
            }

            // Re-add file_path for uploaded M3U files
            if (! Schema::hasColumn('m3u_sources', 'file_path')) {
                $table->string('file_path')->nullable()->after('url');
            }

            // Make url nullable — file-based sources have no URL
            $table->text('url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'file_path']);
            $table->text('url')->nullable(false)->change();
        });
    }
};
