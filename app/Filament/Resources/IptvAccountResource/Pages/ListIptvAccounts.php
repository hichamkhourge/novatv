<?php

namespace App\Filament\Resources\IptvAccountResource\Pages;

use App\Filament\Resources\IptvAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIptvAccounts extends ListRecords
{
    protected static string $resource = IptvAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
