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
            $table->foreignId('tuliprox_server_id')->nullable()->after('m3u_source_id')
                ->constrained('tuliprox_servers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iptv_users', function (Blueprint $table) {
            $table->dropForeign(['tuliprox_server_id']);
            $table->dropColumn('tuliprox_server_id');
        });
    }
};
