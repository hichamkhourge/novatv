<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            // Which provider generated this account ('manual' = no automation)
            $table->string('provider', 50)->default('manual')->after('notes');

            // External reference ID if the provider assigns one (nullable)
            $table->string('provider_account_id')->nullable()->after('provider');

            // Status for async generation: null=not started, pending, done, failed
            $table->string('provider_status', 50)->nullable()->after('provider_account_id');

            // Last error message from the automation job
            $table->text('provider_error')->nullable()->after('provider_status');

            // When the provider credentials were last refreshed
            $table->timestamp('provider_synced_at')->nullable()->after('provider_error');

            $table->index('provider');
            $table->index('provider_status');
        });
    }

    public function down(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropIndex(['provider_status']);
            $table->dropColumn([
                'provider',
                'provider_account_id',
                'provider_status',
                'provider_error',
                'provider_synced_at',
            ]);
        });
    }
};
