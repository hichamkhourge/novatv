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
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->timestamp('retry_scheduled_at')->nullable()->after('provider_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->dropColumn('retry_scheduled_at');
        });
    }
};
