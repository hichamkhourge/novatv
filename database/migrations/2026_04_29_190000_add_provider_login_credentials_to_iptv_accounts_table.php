<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->text('provider_login_email')->nullable()->after('provider_account_id');
            $table->text('provider_login_password')->nullable()->after('provider_login_email');
        });
    }

    public function down(): void
    {
        Schema::table('iptv_accounts', function (Blueprint $table) {
            $table->dropColumn(['provider_login_email', 'provider_login_password']);
        });
    }
};
