<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelGroup;
use App\Models\M3uSource;
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

        $lines = $this->readLines($this->source);

        if ($lines === null) {
            Log::error('ImportM3uJob: Could not read source', ['source' => $this->source]);
            $m3uSource?->update(['status' => 'error', 'error_message' => 'Could not read M3U source URL/file.']);
            return $this->summary;
        }

        $groupCache    = [];   // name -> ChannelGroup model (in-memory cache)
        $pendingExtInf = null;
        $sortOrder     = 0;

        foreach ($lines as $raw) {
            $line = trim($raw);

            if ($line === '' || $line === '#EXTM3U') {
                continue;
            }

            if (str_starts_with($line, '#EXTINF')) {
                $pendingExtInf = $line;
                continue;
            }

            // Stream URL line — must follow an #EXTINF line
            if ($pendingExtInf !== null && ! str_starts_with($line, '#')) {
                $streamUrl = $line;

                try {
                    $attrs = $this->parseExtInf($pendingExtInf);

                    $groupName = $attrs['group-title'] ?: 'Uncategorized';

                    // Resolve or create channel group
                    if (! isset($groupCache[$groupName])) {
                        $groupCache[$groupName] = ChannelGroup::firstOrCreate(
                            ['name' => $groupName],
                            ['slug' => Str::slug($groupName), 'sort_order' => 0, 'is_active' => true],
                        );
                    }

                    $group = $groupCache[$groupName];

                    // Upsert channel — scoped to this source if sourceId provided
                    $query = Channel::where('stream_url', $streamUrl);
                    if ($this->sourceId) {
                        $query->where('m3u_source_id', $this->sourceId);
                    }
                    $existing = $query->first();

                    $data = [
                        'channel_group_id' => $group->id,
                        'm3u_source_id'    => $this->sourceId,
                        'name'             => $attrs['name'] ?: $streamUrl,
                        'logo_url'         => $attrs['tvg-logo'] ?: null,
                        'tvg_id'           => $attrs['tvg-id'] ?: null,
                        'tvg_name'         => $attrs['tvg-name'] ?: null,
                        'sort_order'       => $sortOrder++,
                        'is_active'        => true,
                    ];

                    if ($existing) {
                        $existing->update($data);
                        $this->summary['updated']++;
                    } else {
                        Channel::create(array_merge($data, ['stream_url' => $streamUrl]));
                        $this->summary['created']++;
                    }
                } catch (\Throwable $e) {
                    Log::warning('ImportM3uJob: Skipped malformed entry', [
                        'extinf'     => $pendingExtInf,
                        'stream_url' => $streamUrl,
                        'error'      => $e->getMessage(),
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

        Log::info('ImportM3uJob: Completed', array_merge(['source' => $this->source], $this->summary));

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
}
