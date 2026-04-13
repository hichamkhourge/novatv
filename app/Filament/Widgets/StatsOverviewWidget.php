<?php

namespace App\Filament\Widgets;

use App\Models\Channel;
use App\Models\IptvAccount;
use App\Models\StreamSession;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalAccounts   = IptvAccount::count();
        $activeAccounts  = IptvAccount::active()->count();
        $expiredAccounts = IptvAccount::where('status', 'expired')->count();
        $totalChannels   = Channel::active()->count();
        $liveStreams      = StreamSession::where('last_seen_at', '>', now()->subSeconds(30))->count();

        return [
            Stat::make('Total Accounts', $totalAccounts)
                ->icon('heroicon-o-user-group')
                ->color('gray'),

            Stat::make('Active Accounts', $activeAccounts)
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Expired Accounts', $expiredAccounts)
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Active Channels', $totalChannels)
                ->icon('heroicon-o-tv')
                ->color('info'),

            Stat::make('Live Streams Now', $liveStreams)
                ->icon('heroicon-o-signal')
                ->color('warning'),
        ];
    }
}
