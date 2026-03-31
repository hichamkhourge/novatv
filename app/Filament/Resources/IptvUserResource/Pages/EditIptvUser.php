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
            $shouldUpdate = false;
            $shouldNotify = false;

            // Update or create M3U source
            if ($user->m3u_source_id && $user->m3uSource) {
                // Update existing source
                $m3uSource = $user->m3uSource;

                // Update source name if provided
                if (isset($data['m3u_name']) && $data['m3u_name'] !== $m3uSource->name) {
                    $m3uSource->update(['name' => $data['m3u_name']]);
                }

                // Handle file upload
                if ($data['m3u_source_type'] === 'file' && isset($data['m3u_file']) && !empty($data['m3u_file'])) {
                    // File upload - handle array or string
                    $filePath = is_array($data['m3u_file'])
                        ? ($data['m3u_file'][0] ?? null)
                        : $data['m3u_file'];

                    // Only update if a new file was uploaded (path changed)
                    if ($filePath && $filePath !== $m3uSource->file_path) {
                        $m3uSource->update([
                            'source_type' => 'file',
                            'file_path' => $filePath,
                            'url' => null,
                        ]);

                        // Dispatch sync job for new file
                        SyncM3uSourceJob::dispatch($m3uSource->id);
                        $shouldNotify = true;
                    }
                } elseif ($data['m3u_source_type'] === 'url' && isset($data['m3u_url']) && !empty($data['m3u_url'])) {
                    // Only update if URL changed
                    if ($data['m3u_url'] !== $m3uSource->url) {
                        $m3uSource->update([
                            'source_type' => 'url',
                            'url' => $data['m3u_url'],
                            'file_path' => null,
                        ]);

                        // Dispatch sync job for new URL
                        SyncM3uSourceJob::dispatch($m3uSource->id);
                        $shouldNotify = true;
                    }
                }
            } else {
                // Create new source only if file or URL is provided
                $sourceName = $data['m3u_name'] ?? "{$user->username}'s M3U";

                if ($data['m3u_source_type'] === 'file' && isset($data['m3u_file']) && !empty($data['m3u_file'])) {
                    // File upload - handle array or string
                    $filePath = is_array($data['m3u_file'])
                        ? ($data['m3u_file'][0] ?? null)
                        : $data['m3u_file'];

                    if ($filePath) {
                        $m3uSource = M3uSource::create([
                            'name' => $sourceName,
                            'source_type' => 'file',
                            'file_path' => $filePath,
                            'url' => null,
                            'is_active' => true,
                        ]);

                        $user->update(['m3u_source_id' => $m3uSource->id]);
                        SyncM3uSourceJob::dispatch($m3uSource->id);
                        $shouldNotify = true;
                    }
                } elseif ($data['m3u_source_type'] === 'url' && isset($data['m3u_url']) && !empty($data['m3u_url'])) {
                    $m3uSource = M3uSource::create([
                        'name' => $sourceName,
                        'source_type' => 'url',
                        'url' => $data['m3u_url'],
                        'file_path' => null,
                        'is_active' => true,
                    ]);

                    $user->update(['m3u_source_id' => $m3uSource->id]);
                    SyncM3uSourceJob::dispatch($m3uSource->id);
                    $shouldNotify = true;
                }
            }

            if ($shouldNotify) {
                Notification::make()
                    ->title('M3U source updated')
                    ->body('Changes are being synced in the background.')
                    ->success()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error updating M3U source')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
