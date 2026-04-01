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
        Schema::table('iptv_users', function (Blueprint $table) {
            // Remove direct relationships (moving to pivot table for many-to-many)
            $table->dropForeign(['package_id']);
            $table->dropForeign(['m3u_source_id']);
            $table->dropForeign(['tuliprox_server_id']);

            $table->dropColumn([
                'package_id',
                'm3u_source_id',
                'tuliprox_server_id',
            ]);

            // All required fields already exist from previous migrations:
            // - max_connections (int, default 1)
            // - is_active (bool, default true)
            // - expires_at (nullable timestamp)
            // - notes (nullable text)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iptv_users', function (Blueprint $table) {
            // Restore removed foreign keys
            $table->foreignId('package_id')
                ->nullable()
                ->constrained('packages')
                ->onDelete('set null');

            $table->foreignId('m3u_source_id')
                ->nullable()
                ->constrained('m3u_sources')
                ->onDelete('set null');

            $table->foreignId('tuliprox_server_id')
                ->nullable()
                ->constrained('tuliprox_servers')
                ->onDelete('set null');
        });
    }
};
