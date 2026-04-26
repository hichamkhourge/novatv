<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('iptv_accounts') ||
            ! Schema::hasTable('account_channel_groups') ||
            ! Schema::hasTable('channels')) {
            return;
        }

        $hasRestrictionsColumn = Schema::hasColumn('iptv_accounts', 'has_group_restrictions');

        DB::transaction(function () use ($hasRestrictionsColumn): void {
            $accounts = DB::table('iptv_accounts')
                ->select('id', 'm3u_source_id')
                ->orderBy('id')
                ->get();

            foreach ($accounts as $account) {
                $accountId = (int) $account->id;
                $sourceId = $account->m3u_source_id !== null ? (int) $account->m3u_source_id : null;

                if (! $sourceId) {
                    DB::table('account_channel_groups')
                        ->where('account_id', $accountId)
                        ->delete();

                    continue;
                }

                $validGroupIds = DB::table('channels')
                    ->where('m3u_source_id', $sourceId)
                    ->whereNotNull('channel_group_id')
                    ->distinct()
                    ->pluck('channel_group_id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                if ($validGroupIds === []) {
                    DB::table('account_channel_groups')
                        ->where('account_id', $accountId)
                        ->delete();
                } else {
                    DB::table('account_channel_groups')
                        ->where('account_id', $accountId)
                        ->whereNotIn('channel_group_id', $validGroupIds)
                        ->delete();
                }
            }

            if ($hasRestrictionsColumn) {
                $restrictedAccountIds = DB::table('iptv_accounts')
                    ->where('has_group_restrictions', true)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

                foreach ($restrictedAccountIds as $accountId) {
                    $hasAnyGroups = DB::table('account_channel_groups')
                        ->where('account_id', $accountId)
                        ->exists();

                    if (! $hasAnyGroups) {
                        DB::table('iptv_accounts')
                            ->where('id', $accountId)
                            ->update(['has_group_restrictions' => false]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // One-time data cleanup migration; no down action.
    }
};
