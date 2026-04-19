<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the old check constraint that didn't include 'xtream'
        DB::statement("
            ALTER TABLE m3u_sources
            DROP CONSTRAINT IF EXISTS m3u_sources_source_type_check
        ");

        // Add updated constraint allowing url, file, and xtream
        DB::statement("
            ALTER TABLE m3u_sources
            ADD CONSTRAINT m3u_sources_source_type_check
            CHECK (source_type IN ('url', 'file', 'xtream'))
        ");
    }

    public function down(): void
    {
        DB::statement("
            ALTER TABLE m3u_sources
            DROP CONSTRAINT IF EXISTS m3u_sources_source_type_check
        ");

        DB::statement("
            ALTER TABLE m3u_sources
            ADD CONSTRAINT m3u_sources_source_type_check
            CHECK (source_type IN ('url', 'file'))
        ");
    }
};
