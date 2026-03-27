<?php

namespace App\Filament\Widgets;

use App\Models\IptvUser;
use App\Services\TuliproxService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TuliproxStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tuliproxService = app(TuliproxService::class);
        $stats = $tuliproxService->getStats();

        // Active users from database
        $activeUsers = IptvUser::where('is_active', true)->count();

        // If tuliprox is unreachable, show N/A for channels and groups
        $totalChannels = $stats['total_channels'] ?? 'N/A';
        $totalGroups = $stats['total_groups'] ?? 'N/A';

        return [
            Stat::make('Total Channels', $totalChannels)
                ->description('Channels available in tuliprox')
                ->descriptionIcon('heroicon-o-tv')
                ->color($totalChannels === 'N/A' ? 'gray' : 'success'),

            Stat::make('Total Groups', $totalGroups)
                ->description('Channel groups configured')
                ->descriptionIcon('heroicon-o-rectangle-stack')
                ->color($totalGroups === 'N/A' ? 'gray' : 'info'),

            Stat::make('Active Users', $activeUsers)
                ->description('IPTV users with active status')
                ->descriptionIcon('heroicon-o-user-group')
                ->color('warning'),
        ];
    }

    protected function getPollingInterval(): ?string
    {
        return '30s'; // Refresh every 30 seconds
    }
}
