<?php

namespace App\Filament\Resources\IptvUserResource\Pages;

use App\Filament\Resources\IptvUserResource;
use App\Jobs\SyncM3uSourceJob;
use App\Models\M3uSource;
use Filament\Actions;
use Filament\Notifications\Notification;
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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Load existing M3U source data if exists
        if ($this->record->m3u_source_id && $this->record->m3uSource) {
            $source = $this->record->m3uSource;
            $data['m3u_source_type'] = $source->source_type;
            $data['m3u_name'] = $source->name;

            if ($source->source_type === 'file') {
                $data['m3u_file'] = $source->file_path;
            } else {
                $data['m3u_url'] = $source->url;
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Remove M3U-related fields from user data
        unset($data['m3u_source_type'], $data['m3u_file'], $data['m3u_url'], $data['m3u_name']);

        return $data;
    }

    protected function afterSave(): void
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

            // Update or create M3U source
            if ($user->m3u_source_id && $user->m3uSource) {
                // Update existing source
                $m3uSource = $user->m3uSource;

                if ($data['m3u_source_type'] === 'file' && isset($data['m3u_file'])) {
                    $m3uSource->update([
                        'name' => $sourceName,
                        'source_type' => 'file',
                        'file_path' => $data['m3u_file'],
                        'url' => null,
                    ]);

                    // Dispatch sync job if file changed
                    SyncM3uSourceJob::dispatch($m3uSource->id);
                } elseif ($data['m3u_source_type'] === 'url' && isset($data['m3u_url'])) {
                    $m3uSource->update([
                        'name' => $sourceName,
                        'source_type' => 'url',
                        'url' => $data['m3u_url'],
                        'file_path' => null,
                    ]);

                    // Dispatch sync job if URL changed
                    SyncM3uSourceJob::dispatch($m3uSource->id);
                }
            } else {
                // Create new source
                if ($data['m3u_source_type'] === 'file' && isset($data['m3u_file'])) {
                    $m3uSource = M3uSource::create([
                        'name' => $sourceName,
                        'source_type' => 'file',
                        'file_path' => $data['m3u_file'],
                        'url' => null,
                        'is_active' => true,
                    ]);

                    $user->update(['m3u_source_id' => $m3uSource->id]);
                    SyncM3uSourceJob::dispatch($m3uSource->id);
                } elseif ($data['m3u_source_type'] === 'url' && isset($data['m3u_url'])) {
                    $m3uSource = M3uSource::create([
                        'name' => $sourceName,
                        'source_type' => 'url',
                        'url' => $data['m3u_url'],
                        'file_path' => null,
                        'is_active' => true,
                    ]);

                    $user->update(['m3u_source_id' => $m3uSource->id]);
                    SyncM3uSourceJob::dispatch($m3uSource->id);
                }
            }

            Notification::make()
                ->title('M3U source updated')
                ->body('Changes are being synced in the background.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error updating M3U source')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
