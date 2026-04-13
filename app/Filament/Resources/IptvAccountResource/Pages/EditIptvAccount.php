<?php

namespace App\Filament\Resources\IptvAccountResource\Pages;

use App\Filament\Resources\IptvAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIptvAccount extends EditRecord
{
    protected static string $resource = IptvAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
