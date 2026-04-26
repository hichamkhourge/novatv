<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('account_channel_groups')) {
            return;
        }

        if (! Schema::hasColumn('account_channel_groups', 'sort_order')) {
            Schema::table('account_channel_groups', function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('account_channel_groups')) {
            return;
        }

        if (Schema::hasColumn('account_channel_groups', 'sort_order')) {
            Schema::table('account_channel_groups', function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};
