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
            // Add new fields for the refactored system
            $table->enum('status', ['idle', 'syncing', 'active', 'error'])
                ->default('idle')
                ->after('url');
            $table->integer('channels_count')->default(0)->after('status');
            $table->text('error_message')->nullable()->after('channels_count');

            // Rename last_fetched_at to last_synced_at
            $table->renameColumn('last_fetched_at', 'last_synced_at');

            // Remove tuliprox and automation-related fields
            $table->dropColumn([
                'target_name',
                'source_type',
                'file_path',
                'use_direct_urls',
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('m3u_sources', function (Blueprint $table) {
            // Restore removed fields
            $table->string('target_name')->nullable();
            $table->enum('source_type', ['url', 'file'])->default('url');
            $table->string('file_path')->nullable();
            $table->boolean('use_direct_urls')->default(false);
            $table->string('provider_type')->nullable();
            $table->text('provider_username')->nullable();
            $table->text('provider_password')->nullable();
            $table->json('provider_config')->nullable();
            $table->string('script_path')->nullable();
            $table->boolean('automation_enabled')->default(false);
            $table->timestamp('last_automation_run')->nullable();
            $table->string('automation_status')->nullable();

            // Rename back
            $table->renameColumn('last_synced_at', 'last_fetched_at');

            // Remove new fields
            $table->dropColumn(['status', 'channels_count', 'error_message']);
        });
    }
};
