<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\M3uSource;
use App\Support\ChannelGroupAdultClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Parse an M3U playlist (from URL or local file path) and upsert
 * channels + channel groups into the database, scoped to a specific M3uSource.
 *
 * Returns a summary array: ['created' => N, 'updated' => N, 'skipped' => N]
 */
class ImportM3uJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes for large playlists
    public int $tries   = 1;

    /** @var array{created:int, updated:int, skipped:int} */
    public array $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];

    /**
     * @param string        $source    URL or absolute local file path to an M3U playlist.
     * @param int|null      $sourceId  ID of the M3uSource record to stamp on channels.
     */
    public function __construct(
        public readonly string $source,
        public readonly ?int   $sourceId = null,
    ) {}

    /**
     * Execute the import job.
     */
    public function handle(): array
    {
        $m3uSource = $this->sourceId ? M3uSource::find($this->sourceId) : null;

        // Mark source as syncing
        $m3uSource?->update(['status' => 'syncing']);

        // Groups to skip (e.g. ["24/7"] to skip VOD entries on zazy-type providers)
        $excludedGroups = [];
        if ($m3uSource && ! empty($m3uSource->excluded_groups)) {
            $raw            = $m3uSource->excluded_groups;
            $excludedGroups = is_array($raw) ? $raw : (json_decode($raw, true) ?? []);
            $excludedGroups = array_map('strtolower', $excludedGroups);
        }

        $lines = $this->readLines($this->source);

        if ($lines === null) {
            Log::error('ImportM3uJob: Could not read source', ['source' => $this->source]);
            $m3uSource?->update(['status' => 'error', 'error_message' => 'Could not read M3U source URL/file.']);
            return $this->summary;
        }

        // Delete old channels for a clean re-import (avoids duplicates on re-sync)
        if ($this->sourceId) {
            Channel::where('m3u_source_id', $this->sourceId)->delete();
        }

        $groupCache    = [];        // groupName -> group id
        $pendingExtInf = null;
        $sortOrder     = 0;
        $batch         = [];
        $batchSize     = 500;
        $now           = now()->toDateTimeString();

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ($line === '' || $line === '#EXTM3U' || str_starts_with($line, '#EXT-X-')) {
                continue;
            }

            if (str_starts_with($line, '#EXTINF')) {
                $pendingExtInf = $line;
                continue;
            }

            // Stream URL line — must follow an #EXTINF line
            if ($pendingExtInf !== null && ! str_starts_with($line, '#')) {
                $streamUrl = trim($line);

                try {
                    $attrs     = $this->parseExtInf($pendingExtInf);
                    $channelName = $attrs['name'] ?: $streamUrl;

                    // Skip VOD content (movies/series)
                    if ($this->isVodContent($streamUrl, $channelName)) {
                        $this->summary['skipped_vod'] = ($this->summary['skipped_vod'] ?? 0) + 1;
                        $pendingExtInf = null;
                        continue;
                    }

                    $groupName = $attrs['group-title'] ?: 'Uncategorized';

                    // Skip excluded groups (improved matching: exact, prefix, or substring)
                    if (! empty($excludedGroups)) {
                        $groupLower = strtolower($groupName);
                        $excluded = false;

                        foreach ($excludedGroups as $pattern) {
                            $patternLower = strtolower($pattern);

                            // Match if exact, starts with, or contains pattern
                            if ($groupLower === $patternLower ||
                                str_starts_with($groupLower, $patternLower) ||
                                str_contains($groupLower, $patternLower)) {
                                $excluded = true;
                                break;
                            }
                        }

                        if ($excluded) {
                            $this->summary['skipped']++;
                            $pendingExtInf = null;
                            continue;
                        }
                    }

                    // Resolve or create channel group (cache group id by name)
                    if (! isset($groupCache[$groupName])) {
                        $group                  = ChannelGroup::firstOrCreate(
                            ['name' => $groupName],
                            [
                                'slug' => Str::slug($groupName),
                                'sort_order' => 0,
                                'is_active' => true,
                                'is_adult' => ChannelGroupAdultClassifier::isAdult($groupName),
                            ],
                        );
                        $groupCache[$groupName] = $group->id;
                    }

                    $batch[] = [
                        'channel_group_id' => $groupCache[$groupName],
                        'm3u_source_id'    => $this->sourceId,
                        'stream_url'       => $streamUrl,
                        'name'             => $attrs['name'] ?: $streamUrl,
                        'logo_url'         => $attrs['tvg-logo'] ?: null,
                        'tvg_id'           => $attrs['tvg-id'] ?: null,
                        'tvg_name'         => $attrs['tvg-name'] ?: null,
                        'sort_order'       => $sortOrder++,
                        'is_active'        => true,
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ];

                    $this->summary['created']++;

                    // Flush batch every $batchSize rows
                    if (count($batch) >= $batchSize) {
                        Channel::insert($batch);
                        $batch = [];
                    }
                } catch (\Throwable $e) {
                    Log::warning('ImportM3uJob: Skipped malformed entry', [
                        'extinf' => $pendingExtInf,
                        'url'    => $streamUrl,
                        'error'  => $e->getMessage(),
                    ]);
                    $this->summary['skipped']++;
                }

                $pendingExtInf = null;
                continue;
            }

            // Non-stream, non-EXTINF comment — reset pending
            if (str_starts_with($line, '#')) {
                $pendingExtInf = null;
            }
        }

        // Flush remaining rows
        if (! empty($batch)) {
            Channel::insert($batch);
        }

        // Update M3uSource stats
        if ($m3uSource) {
            $channelsCount = Channel::where('m3u_source_id', $this->sourceId)->count();
            $m3uSource->update([
                'status'         => 'idle',
                'channels_count' => $channelsCount,
                'last_synced_at' => now(),
                'error_message'  => null,
            ]);
        }

        // Count total groups created
        $this->summary['groups'] = count($groupCache);

        Log::info('ImportM3uJob: Completed', [
            'source' => $this->source,
            'source_id' => $this->sourceId,
            'channels_imported' => $this->summary['created'],
            'groups_created' => $this->summary['groups'],
            'skipped_excluded_groups' => $this->summary['skipped'] ?? 0,
            'skipped_vod_content' => $this->summary['skipped_vod'] ?? 0,
            'total_processed' => $this->summary['created'] + ($this->summary['skipped'] ?? 0) + ($this->summary['skipped_vod'] ?? 0),
        ]);

        return $this->summary;
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        if ($this->sourceId) {
            M3uSource::where('id', $this->sourceId)->update([
                'status'        => 'error',
                'error_message' => $exception->getMessage(),
            ]);
        }
        Log::error('ImportM3uJob: Failed', ['source' => $this->source, 'error' => $exception->getMessage()]);
    }

    /**
     * Parse a single #EXTINF line and return named attributes.
     *
     * Handles two M3U formats:
     *   Extended (m3u_plus): #EXTINF:-1 tvg-id="x" group-title="Group",Name
     *   Simple   (m3u):      #EXTINF:-1,Name
     *
     * For the simple format (no attributes), the group is inferred from the
     * channel name prefix:
     *   "USA AMC"         → group "USA"
     *   "LA: Univision"   → group "LA"
     *   "24/7 Yellowstone" → group "24/7"
     *   "Match Centre #1" → group "Match Centre"
     *
     * @return array{tvg-id:string, tvg-name:string, tvg-logo:string, group-title:string, name:string}
     */
    private function parseExtInf(string $line): array
    {
        $attrs = [
            'tvg-id'      => '',
            'tvg-name'    => '',
            'tvg-logo'    => '',
            'group-title' => '',
            'name'        => '',
        ];

        // Extract the display name after the last comma
        if (($commaPos = strrpos($line, ',')) !== false) {
            $attrs['name'] = trim(substr($line, $commaPos + 1));
        }

        // Extract key="value" attribute pairs (extended m3u_plus format)
        foreach (array_keys($attrs) as $key) {
            if ($key === 'name') {
                continue;
            }
            if (preg_match('/' . preg_quote($key, '/') . '="([^"]*)"/', $line, $m)) {
                $attrs[$key] = $m[1];
            }
        }

        // Sub-group extraction (if enabled) - takes priority over group-title attribute
        if ($this->shouldExtractSubgroups() && $attrs['name'] !== '') {
            $extracted = $this->extractSubGroup($attrs['name']);
            if ($extracted !== null) {
                $attrs['group-title'] = $extracted;

                // Optionally clean channel name by removing the extracted prefix
                $attrs['name'] = preg_replace(
                    '/^' . preg_quote($extracted, '/') . '\s*[-–—]\s*/',
                    '',
                    $attrs['name']
                );
            }
        }

        // If no group-title found, infer from channel name prefix (simple M3U format)
        if ($attrs['group-title'] === '' && $attrs['name'] !== '') {
            $name = $attrs['name'];

            if (preg_match('/^([A-Z0-9\/]{2,6}):\s+/', $name, $m)) {
                // "LA: Univision", "SUR: RCN", "USA: CNN" → prefix before colon
                $attrs['group-title'] = rtrim($m[1], ':');
            } elseif (preg_match('/^(24\/7|USA|UK|AR|FR|DE|ES|IT|NL|PT|TR|CA|AU)\s/', $name, $m)) {
                // "USA AMC", "24/7 Yellowstone"
                $attrs['group-title'] = trim($m[1]);
            } elseif (preg_match('/^([A-Z][a-zA-Z ]+?)\s+#\d/', $name, $m)) {
                // "Match Centre #1" → "Match Centre"
                $attrs['group-title'] = trim($m[1]);
            }
        }

        return $attrs;
    }

    /**
     * Read lines from a URL or local file path.
     * Returns null on failure.
     *
     * @return iterable<string>|null
     */
    private function readLines(string $source): ?iterable
    {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            $context = stream_context_create([
                'http' => [
                    'timeout'    => 60,
                    'user_agent' => 'Mozilla/5.0 M3U Importer',
                ],
            ]);

            $handle = @fopen($source, 'r', false, $context);
        } else {
            $handle = @fopen($source, 'r');
        }

        if ($handle === false) {
            return null;
        }

        return (function () use ($handle) {
            while (! feof($handle)) {
                // Strip Windows-style CRLF — fgets keeps the \r on Windows M3U files
                yield rtrim(fgets($handle), "\r\n") . "\n";
            }
            fclose($handle);
        })();
    }

    /**
     * Check if stream URL or channel name indicates VOD/series content.
     *
     * Detects movies and TV series by:
     * - File extensions (.mp4, .mkv, .avi, etc.)
     * - URL patterns (/movie/, /series/, /vod/)
     * - Episode naming patterns (S01 E01, Season 1, etc.)
     *
     * @param string $streamUrl The stream URL
     * @param string $channelName The channel display name
     * @return bool True if this is VOD content, false if live TV
     */
    private function isVodContent(string $streamUrl, string $channelName): bool
    {
        // Method 1: File extension check
        $vodExtensions = ['mp4', 'mkv', 'avi', 'm4v', 'mov', 'wmv', 'flv', 'webm'];
        $urlPath = parse_url($streamUrl, PHP_URL_PATH);
        $extension = $urlPath ? strtolower(pathinfo($urlPath, PATHINFO_EXTENSION)) : '';

        if (in_array($extension, $vodExtensions, true)) {
            return true;
        }

        // Method 2: URL pattern check
        $vodPatterns = ['/movie/', '/vod/', '/series/', '/film/', '/tv-shows/', '/tvshows/'];
        foreach ($vodPatterns as $pattern) {
            if (stripos($streamUrl, $pattern) !== false) {
                return true;
            }
        }

        // Method 3: Episode naming pattern
        $episodePatterns = [
            '/S\d{2}\s*E\d{2}/i',     // S01 E01, S02E12
            '/Season\s*\d+/i',         // Season 1
            '/Episode\s*\d+/i',        // Episode 1
            '/\sEp\s*\d+/i',          // Ep 1
        ];

        foreach ($episodePatterns as $pattern) {
            if (preg_match($pattern, $channelName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if sub-group extraction is enabled for this source.
     *
     * @return bool
     */
    private function shouldExtractSubgroups(): bool
    {
        if (!$this->sourceId) {
            return false;
        }

        static $cachedSetting = null;
        if ($cachedSetting === null) {
            $source = M3uSource::find($this->sourceId);
            $cachedSetting = $source?->extract_subgroups ?? false;
        }

        return $cachedSetting;
    }

    /**
     * Extract sub-group from channel name prefix.
     *
     * Examples:
     * - "AR Morocco - MBC 1" → "AR Morocco"
     * - "AR Tunisia - Nessma TV" → "AR Tunisia"
     * - "[US] CNN" → "US"
     * - "(UK) BBC" → "UK"
     * - "USA | CNN" → "USA"
     *
     * @param string $channelName The channel display name
     * @return string|null The extracted sub-group name, or null if no pattern matched
     */
    private function extractSubGroup(string $channelName): ?string
    {
        // Pattern 1: "AR Morocco - Channel" → "AR Morocco"
        // Matches: 2-3 uppercase letters + space + word + hyphen
        if (preg_match('/^([A-Z]{2,3}\s+[A-Za-z0-9]+)\s*[-–—]\s*/', $channelName, $m)) {
            return trim($m[1]);
        }

        // Pattern 2: "[US] CNN" → "US"
        if (preg_match('/^\[([A-Z]{2,3})\]\s*/', $channelName, $m)) {
            return trim($m[1]);
        }

        // Pattern 3: "(UK) BBC" → "UK"
        if (preg_match('/^\(([A-Z]{2,3})\)\s*/', $channelName, $m)) {
            return trim($m[1]);
        }

        // Pattern 4: "USA | CNN" → "USA"
        if (preg_match('/^([A-Z]{2,3})\s*\|\s*/', $channelName, $m)) {
            return trim($m[1]);
        }

        return null;
    }
}
