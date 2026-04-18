<?php

namespace App\Filament\Resources\M3uSourceResource\Pages;

use App\Filament\Resources\M3uSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditM3uSource extends EditRecord
{
    protected static string $resource = M3uSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
