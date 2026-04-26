<?php

namespace App\Filament\Resources\IptvAccountResource\Pages;

use App\Filament\Resources\IptvAccountResource;
use App\Jobs\GenerateProviderAccountJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateIptvAccount extends CreateRecord
{
    protected static string $resource = IptvAccountResource::class;

    protected function afterCreate(): void
    {
        $account = $this->record;

        if ($account->provider === 'manual') {
            return;
        }

        // Dispatch the automation job to generate provider credentials in the background
        GenerateProviderAccountJob::dispatch($account->id, isRenewal: false)
            ->onQueue('default');

        Notification::make()
            ->title('⏳ Generating ' . ucfirst($account->provider) . ' credentials')
            ->body('The automation script is running in the background (2–8 min). This page will show "pending" until credentials are ready.')
            ->warning()
            ->persistent()
            ->send();
    }
}
