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
            $table->enum('provider_type', ['ugeen', 'zazy', 'custom', 'none'])->default('none')->after('use_direct_urls');
            $table->text('provider_username')->nullable()->after('provider_type');
            $table->text('provider_password')->nullable()->after('provider_username');
            $table->json('provider_config')->nullable()->after('provider_password');
            $table->string('script_path')->nullable()->after('provider_config');
            $table->boolean('automation_enabled')->default(false)->after('script_path');
            $table->timestamp('last_automation_run')->nullable()->after('automation_enabled');
            $table->text('automation_status')->nullable()->after('last_automation_run');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            $table->dropColumn([
                'provider_type',
                'provider_username',
                'provider_password',
                'provider_config',
                'script_path',
                'automation_enabled',
                'last_automation_run',
                'automation_status',
            ]);
        });
    }
};
