<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('iptv_accounts', 'm3u_source_id')) {
                $table->foreignId('m3u_source_id')
                    ->nullable()
                    ->constrained('m3u_sources')
                    ->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->dropForeign(['m3u_source_id']);
            $table->dropColumn('m3u_source_id');
        });
    }
};