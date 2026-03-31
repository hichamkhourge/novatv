<?php

namespace App\Filament\Resources\IptvUserResource\Pages;

use App\Filament\Resources\IptvUserResource;
use App\Jobs\SyncM3uSourceJob;
use App\Models\M3uSource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateIptvUser extends CreateRecord
{
    protected static string $resource = IptvUserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Remove M3U-related fields from user data
        unset($data['m3u_source_type'], $data['m3u_file'], $data['m3u_url'], $data['m3u_name']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $data = $this->form->getRawState();
        $user = $this->record;

        // Check if M3U source data is provided
        if (!isset($data['m3u_source_type'])) {
            return;
        }

        try {
            // Generate source name if not provided
            $sourceName = $data['m3u_name'] ?? "{$user->username}'s M3U";

            // Create M3U source based on type
            if ($data['m3u_source_type'] === 'file' && isset($data['m3u_file'])) {
                // File upload
                $m3uSource = M3uSource::create([
                    'name' => $sourceName,
                    'source_type' => 'file',
                    'file_path' => $data['m3u_file'],
                    'url' => null,
                    'is_active' => true,
                ]);

                // Link to user
                $user->update(['m3u_source_id' => $m3uSource->id]);

                // Dispatch sync job immediately
                SyncM3uSourceJob::dispatch($m3uSource->id);

                Notification::make()
                    ->title('User created successfully')
                    ->body('M3U file is being synced in the background.')
                    ->success()
                    ->send();
            } elseif ($data['m3u_source_type'] === 'url' && isset($data['m3u_url'])) {
                // URL-based
                $m3uSource = M3uSource::create([
                    'name' => $sourceName,
                    'source_type' => 'url',
                    'url' => $data['m3u_url'],
                    'file_path' => null,
                    'is_active' => true,
                ]);

                // Link to user
                $user->update(['m3u_source_id' => $m3uSource->id]);

                // Dispatch sync job immediately
                SyncM3uSourceJob::dispatch($m3uSource->id);

                Notification::make()
                    ->title('User created successfully')
                    ->body('M3U source is being synced in the background.')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error creating M3U source')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
