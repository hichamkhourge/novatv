<?php

namespace App\Filament\Resources\ChannelGroupResource\Pages;

use App\Filament\Resources\ChannelGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditChannelGroup extends EditRecord
{
    protected static string $resource = ChannelGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
