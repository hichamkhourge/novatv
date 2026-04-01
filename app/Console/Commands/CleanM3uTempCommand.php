<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanM3uTempCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'm3u:clean-temp';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old temporary M3U files (older than 1 hour)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tempPath = storage_path('app/temp');

        if (!is_dir($tempPath)) {
            $this->info('Temp directory does not exist. Nothing to clean.');
            return self::SUCCESS;
        }

        $cutoffTime = now()->subHour()->timestamp;
        $deletedCount = 0;
        $deletedSize = 0;

        $files = Storage::files('temp');

        foreach ($files as $file) {
            $fullPath = Storage::path($file);

            // Check if file is older than 1 hour
            if (file_exists($fullPath) && filemtime($fullPath) < $cutoffTime) {
                $fileSize = filesize($fullPath);

                if (Storage::delete($file)) {
                    $deletedCount++;
                    $deletedSize += $fileSize;
                    $this->line("Deleted: {$file}");
                }
            }
        }

        if ($deletedCount > 0) {
            $deletedSizeMB = round($deletedSize / 1024 / 1024, 2);
            $this->info("Cleaned up {$deletedCount} file(s), freed {$deletedSizeMB} MB.");
        } else {
            $this->info('No old temp files found.');
        }

        return self::SUCCESS;
    }
}
