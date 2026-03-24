<?php

namespace App\Filament\Resources\IptvUserResource\Pages;

use App\Filament\Resources\IptvUserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIptvUser extends EditRecord
{
    protected static string $resource = IptvUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
