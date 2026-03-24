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
        if (!Schema::hasColumn('m3u_sources', 'use_direct_urls')) {
            Schema::table('m3u_sources', function (Blueprint $table) {
                $table->boolean('use_direct_urls')->default(false)->after('is_active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('m3u_sources', 'use_direct_urls')) {
            Schema::table('m3u_sources', function (Blueprint $table) {
                $table->dropColumn('use_direct_urls');
            });
        }
    }
};
