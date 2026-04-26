<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('iptv_accounts')) {
            Schema::table('iptv_accounts', function (Blueprint $table) {
                $existing = Schema::getColumnListing('iptv_accounts');

                if (! in_array('has_group_restrictions', $existing, true)) {
                    $table->boolean('has_group_restrictions')->default(false);
                }

                if (! in_array('allow_adult', $existing, true)) {
                    $table->boolean('allow_adult')->default(false);
                }
            });
        }

        if (Schema::hasTable('channel_groups')) {
            Schema::table('channel_groups', function (Blueprint $table) {
                $existing = Schema::getColumnListing('channel_groups');

                if (! in_array('is_adult', $existing, true)) {
                    $table->boolean('is_adult')->default(false);
                }
            });
        }

        if (Schema::hasTable('iptv_accounts') && Schema::hasTable('account_channel_groups')) {
            DB::statement('
                UPDATE iptv_accounts AS accounts
                SET has_group_restrictions = TRUE
                WHERE EXISTS (
                    SELECT 1
                    FROM account_channel_groups AS acg
                    WHERE acg.account_id = accounts.id
                )
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('iptv_accounts')) {
            Schema::table('iptv_accounts', function (Blueprint $table) {
                $existing = Schema::getColumnListing('iptv_accounts');

                if (in_array('has_group_restrictions', $existing, true)) {
                    $table->dropColumn('has_group_restrictions');
                }

                if (in_array('allow_adult', $existing, true)) {
                    $table->dropColumn('allow_adult');
                }
            });
        }

        if (Schema::hasTable('channel_groups') && Schema::hasColumn('channel_groups', 'is_adult')) {
            Schema::table('channel_groups', function (Blueprint $table) {
                $table->dropColumn('is_adult');
            });
        }
    }
};
