<?php

namespace App\Filament\Resources\ChannelGroupResource\Pages;

use App\Filament\Resources\ChannelGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListChannelGroups extends ListRecords
{
    protected static string $resource = ChannelGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
