<?php

namespace App\Filament\Resources\IptvUserResource\Pages;

use App\Filament\Resources\IptvUserResource;
use App\Services\TuliproxService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListIptvUsers extends ListRecords
{
    protected static string $resource = IptvUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('sync_tuliprox')
                ->label('Sync All to Tuliprox')
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(function () {
                    try {
                        $tuliproxService = app(TuliproxService::class);
                        $tuliproxService->syncAll();

                        Notification::make()
                            ->title('Tuliprox Configuration Synced')
                            ->success()
                            ->body('All Tuliprox configuration files have been updated successfully.')
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Sync Failed')
                            ->danger()
                            ->body('Failed to sync Tuliprox configuration: ' . $e->getMessage())
                            ->send();
                    }
                })
                ->requiresConfirmation()
                ->modalHeading('Sync Tuliprox Configuration')
                ->modalDescription('This will regenerate all Tuliprox configuration files (user.yml, source.yml, api-proxy.yml) based on current database state.')
                ->modalSubmitActionLabel('Sync Now'),
            Actions\CreateAction::make(),
        ];
    }
}
