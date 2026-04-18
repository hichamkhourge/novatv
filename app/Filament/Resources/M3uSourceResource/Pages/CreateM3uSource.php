<?php

namespace App\Filament\Resources\M3uSourceResource\Pages;

use App\Filament\Resources\M3uSourceResource;
use App\Jobs\ImportM3uJob;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateM3uSource extends CreateRecord
{
    protected static string $resource = M3uSourceResource::class;

    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        // Auto-trigger sync after creation if a source is provided
        $source = $record->source_type === 'file'
            ? $record->getFullFilePath()
            : $record->url;

        if ($source) {
            ImportM3uJob::dispatch($source, $record->id);

            Notification::make()
                ->title('M3U Sync Started')
                ->body("Importing channels for \"{$record->name}\" in the background.")
                ->success()
                ->send();
        }
    }
}
