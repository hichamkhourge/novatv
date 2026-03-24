<?php

namespace App\Filament\Resources\IptvUserResource\Pages;

use App\Filament\Resources\IptvUserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIptvUsers extends ListRecords
{
    protected static string $resource = IptvUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
