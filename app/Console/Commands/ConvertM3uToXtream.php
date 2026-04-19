<?php

namespace App\Console\Commands;

use App\Jobs\ImportXtreamJob;
use App\Models\IptvAccount;
use App\Models\M3uSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ConvertM3uToXtream extends Command
{
    protected $signature = 'iptv:convert-m3u-to-xtream';
    protected $description = 'Convert M3U URL sources to Xtream API sources for ugeen and zazy';

    public function handle()
    {
        $this->info('Starting conversion of M3U sources to Xtream API...');

        DB::beginTransaction();

        try {
            // Define the sources to convert with their credentials
            $conversions = [
                'yansinkrad' => [ // Username from URL
                    'name' => 'ugeen - Xtream API',
                    'xtream_host' => 'https://novatv.novadevlabs.com',
                    'xtream_username' => 'yansinkrad',
                    'xtream_password' => 'yansinkrad',
                ],
                'zazy' => [
                    'name' => 'zazy - Xtream API',
                    'xtream_host' => 'https://novatv.novadevlabs.com',
                    'xtream_username' => 'zazy',
                    'xtream_password' => 'zazy',
                ],
            ];

            foreach ($conversions as $username => $config) {
                $this->info("\nProcessing: {$username}");

                // Find existing M3U source by URL pattern (contains username)
                $oldSource = M3uSource::where('source_type', 'url')
                    ->where(function($q) use ($username) {
                        $q->where('url', 'like', "%username={$username}%")
                          ->orWhere('name', 'like', "%{$username}%");
                    })
                    ->first();

                if ($oldSource) {
                    $this->warn("  Found existing M3U source: {$oldSource->name} (ID: {$oldSource->id})");
                    $this->warn("  URL: {$oldSource->url}");
                    $this->warn("  Deleting old M3U source and its channels...");
                    $oldSource->delete(); // Cascade will delete related channels
                    $this->info("  Deleted successfully");
                } else {
                    $this->comment("  No existing M3U source found for username: {$username}");
                }

                // Check if Xtream source already exists
                $existingXtream = M3uSource::where('source_type', 'xtream')
                    ->where('xtream_host', $config['xtream_host'])
                    ->where('xtream_username', $config['xtream_username'])
                    ->first();

                if ($existingXtream) {
                    $this->warn("  Xtream source already exists (ID: {$existingXtream->id})");
                    $newSource = $existingXtream;
                } else {
                    // Create new Xtream API source
                    $newSource = M3uSource::create([
                        'name' => $config['name'],
                        'source_type' => 'xtream',
                        'xtream_host' => $config['xtream_host'],
                        'xtream_username' => $config['xtream_username'],
                        'xtream_password' => $config['xtream_password'],
                        'url' => null,
                        'file_path' => null,
                        'is_active' => true,
                    ]);

                    $this->info("  Created new Xtream API source (ID: {$newSource->id})");
                }

                // Dispatch import job
                $this->info("  Dispatching ImportXtreamJob...");
                ImportXtreamJob::dispatch($newSource->id);
            }

            DB::commit();

            $this->info("\n✓ Conversion completed successfully!");
            $this->info("Import jobs have been dispatched. Channels will be imported shortly.");
            $this->comment("\nMonitor queue with: docker compose exec app php artisan queue:work");

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("\n✗ Conversion failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
