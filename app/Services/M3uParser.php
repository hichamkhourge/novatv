<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\Log;

/**
 * Memory-efficient M3U parser using PHP generators
 * Can handle files with 50,000-200,000+ channels
 */
class M3uParser
{
    /**
     * Parse M3U file and yield channels in chunks
     *
     * @param string $filePath Full path to M3U file
     * @param int $sourceId M3U source ID
     * @param int $chunkSize Number of channels per chunk
     * @return Generator Yields arrays of parsed channel data
     */
    public function parseInChunks(string $filePath, int $sourceId, int $chunkSize = 500): Generator
    {
        if (!file_exists($filePath)) {
            throw new \Exception("M3U file not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \Exception("Failed to open M3U file: {$filePath}");
        }

        $chunk = [];
        $totalParsed = 0;
        $totalSkipped = 0;
        $currentExtinf = null;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                // Skip empty lines
                if (empty($line)) {
                    continue;
                }

                // Skip header
                if (stripos($line, '#EXTM3U') === 0) {
                    continue;
                }

                // Parse #EXTINF line
                if (stripos($line, '#EXTINF:') === 0) {
                    $currentExtinf = $this->parseExtinf($line);
                    continue;
                }

                // Skip comments that aren't EXTINF
                if (str_starts_with($line, '#')) {
                    continue;
                }

                // This line should be the stream URL
                if ($currentExtinf !== null && filter_var($line, FILTER_VALIDATE_URL)) {
                    $channel = [
                        'm3u_source_id' => $sourceId,
                        'stream_id' => $currentExtinf['stream_id'] ?? null,
                        'name' => $currentExtinf['name'] ?? 'Unknown Channel',
                        'stream_url' => $line,
                        'logo' => $currentExtinf['logo'] ?? null,
                        'category' => $currentExtinf['category'] ?? null,
                        'epg_id' => $currentExtinf['epg_id'] ?? null,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $chunk[] = $channel;
                    $totalParsed++;

                    // Yield chunk when it reaches the chunk size
                    if (count($chunk) >= $chunkSize) {
                        yield $chunk;
                        $chunk = [];
                    }

                    $currentExtinf = null;
                } else {
                    // Malformed line - skip gracefully
                    if ($currentExtinf !== null) {
                        $totalSkipped++;
                        Log::debug("M3U Parser: Skipped malformed entry", [
                            'extinf' => $currentExtinf,
                            'url' => $line,
                        ]);
                    }
                    $currentExtinf = null;
                }
            }

            // Yield remaining channels in the last chunk
            if (!empty($chunk)) {
                yield $chunk;
            }

            Log::info("M3U Parser: Completed parsing", [
                'source_id' => $sourceId,
                'total_parsed' => $totalParsed,
                'total_skipped' => $totalSkipped,
            ]);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse #EXTINF line to extract channel metadata
     *
     * Format: #EXTINF:-1 tvg-id="..." tvg-name="..." tvg-logo="..." group-title="...",Channel Name
     *
     * @param string $line EXTINF line
     * @return array Parsed attributes
     */
    private function parseExtinf(string $line): array
    {
        $data = [];

        // Extract stream_id if present (sometimes in tvg-id as number)
        if (preg_match('/tvg-id="([^"]*)"/', $line, $matches)) {
            $data['epg_id'] = $matches[1];
            // If tvg-id is numeric, use as stream_id
            if (is_numeric($matches[1])) {
                $data['stream_id'] = (int) $matches[1];
            }
        }

        // Extract tvg-logo (logo URL)
        if (preg_match('/tvg-logo="([^"]*)"/', $line, $matches)) {
            $data['logo'] = $matches[1];
        }

        // Extract group-title (category)
        if (preg_match('/group-title="([^"]*)"/', $line, $matches)) {
            $data['category'] = $matches[1];
        }

        // Extract channel name (after the last comma)
        if (preg_match('/,(.+)$/', $line, $matches)) {
            $data['name'] = trim($matches[1]);
        }

        return $data;
    }
}
