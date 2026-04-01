<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Memory-safe M3U file downloader
 * Streams large M3U files to disk without loading into memory
 */
class M3uDownloader
{
    /**
     * Download M3U file from URL and save to temporary storage
     *
     * @param int $sourceId M3U source ID
     * @param string $url URL to download from
     * @return string Path to downloaded temp file
     * @throws \Exception on download failure
     */
    public function download(int $sourceId, string $url): string
    {
        $timestamp = now()->timestamp;
        $filename = "m3u_{$sourceId}_{$timestamp}.m3u";
        $tempPath = "temp/{$filename}";
        $fullPath = Storage::path($tempPath);

        // Ensure temp directory exists
        Storage::makeDirectory('temp');

        Log::info("M3U Download: Starting download for source {$sourceId}", [
            'url' => $url,
            'temp_file' => $tempPath,
        ]);

        try {
            // Stream download to file
            $response = Http::timeout(300) // 5 minute timeout
                ->withOptions([
                    'stream' => true,
                    'sink' => $fullPath,
                ])
                ->get($url);

            if (!$response->successful()) {
                throw new \Exception(
                    "Failed to download M3U from {$url}. HTTP status: {$response->status()}"
                );
            }

            // Verify file was created
            if (!file_exists($fullPath)) {
                throw new \Exception("Downloaded file not found at {$fullPath}");
            }

            $fileSize = filesize($fullPath);
            $fileSizeMB = round($fileSize / 1024 / 1024, 2);

            Log::info("M3U Download: Successfully downloaded {$fileSizeMB} MB", [
                'source_id' => $sourceId,
                'file_size_bytes' => $fileSize,
                'temp_file' => $tempPath,
            ]);

            return $fullPath;
        } catch (\Exception $e) {
            // Clean up failed download
            if (Storage::exists($tempPath)) {
                Storage::delete($tempPath);
            }

            Log::error("M3U Download: Failed for source {$sourceId}", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Delete temporary M3U file
     *
     * @param string $filePath Full path to temp file
     * @return bool Success status
     */
    public function cleanup(string $filePath): bool
    {
        try {
            if (file_exists($filePath)) {
                unlink($filePath);
                Log::info("M3U Download: Cleaned up temp file", ['file' => $filePath]);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::warning("M3U Download: Failed to cleanup temp file", [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
