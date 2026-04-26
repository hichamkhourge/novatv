<?php

use App\Support\ChannelGroupAdultClassifier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('channel_groups') || ! Schema::hasColumn('channel_groups', 'is_adult')) {
            return;
        }

        DB::table('channel_groups')
            ->select(['id', 'name'])
            ->where('is_adult', false)
            ->orderBy('id')
            ->chunkById(200, function ($groups): void {
                $adultGroupIds = [];

                foreach ($groups as $group) {
                    if (ChannelGroupAdultClassifier::isAdult($group->name)) {
                        $adultGroupIds[] = (int) $group->id;
                    }
                }

                if ($adultGroupIds === []) {
                    return;
                }

                DB::table('channel_groups')
                    ->whereIn('id', $adultGroupIds)
                    ->update([
                        'is_adult' => true,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        //
    }
};
