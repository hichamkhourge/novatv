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
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->string('provider_type', 50)->nullable()->after('is_active');
            $table->text('provider_username')->nullable()->after('provider_type');
            $table->text('provider_password')->nullable()->after('provider_username');
            $table->json('provider_config')->nullable()->after('provider_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->dropColumn(['provider_type', 'provider_username', 'provider_password', 'provider_config']);
        });
    }
};
