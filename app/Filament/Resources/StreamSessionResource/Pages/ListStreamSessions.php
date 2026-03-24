<?php

namespace App\Filament\Resources\StreamSessionResource\Pages;

use App\Filament\Resources\StreamSessionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStreamSessions extends ListRecords
{
    protected static string $resource = StreamSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
