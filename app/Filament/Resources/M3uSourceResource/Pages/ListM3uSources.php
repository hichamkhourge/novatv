<?php

namespace App\Filament\Resources\M3uSourceResource\Pages;

use App\Filament\Resources\M3uSourceResource;
use Filament\Resources\Pages\ListRecords;

class ListM3uSources extends ListRecords
{
    protected static string $resource = M3uSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
        ];
    }
}
