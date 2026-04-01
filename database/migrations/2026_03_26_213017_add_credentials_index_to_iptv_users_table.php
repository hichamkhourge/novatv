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
            // Composite index for authentication queries
            // Every stream request authenticates with username+password
            $table->index(['username', 'password'], 'idx_credentials');

            // Index for active status checks
            $table->index('is_active', 'idx_is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('iptv_users', function (Blueprint $table) {
            $table->dropIndex('idx_credentials');
            $table->dropIndex('idx_is_active');
        });
    }
};
