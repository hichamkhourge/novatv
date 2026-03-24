<?php

namespace App\Filament\Resources\StreamSessionResource\Pages;

use App\Filament\Resources\StreamSessionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStreamSession extends EditRecord
{
    protected static string $resource = StreamSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
