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
            $table->foreignId('m3u_source_id')
                ->nullable()
                ->after('package_id')
                ->constrained('m3u_sources')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iptv_users', function (Blueprint $table) {
            $table->dropForeign(['m3u_source_id']);
            $table->dropColumn('m3u_source_id');
        });
    }
};
