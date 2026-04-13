<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\ChannelGroup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Parse an M3U playlist (from URL or local file path) and upsert
 * channels + channel groups into the database.
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
     * @param string $source URL or absolute local file path to an M3U playlist.
     */
    public function __construct(public readonly string $source) {}

    /**
     * Execute the import job.
     */
    public function handle(): array
    {
        $lines = $this->readLines($this->source);

        if ($lines === null) {
            Log::error('ImportM3uJob: Could not read source', ['source' => $this->source]);
            return $this->summary;
        }

        $groupCache  = [];  // name -> ChannelGroup model (in-memory cache)
        $pendingExtInf = null;

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

                    // Upsert channel by stream_url
                    $existing = Channel::where('stream_url', $streamUrl)->first();

                    $data = [
                        'channel_group_id' => $group->id,
                        'name'             => $attrs['name'] ?: $streamUrl,
                        'logo_url'         => $attrs['tvg-logo'] ?: null,
                        'tvg_id'           => $attrs['tvg-id'] ?: null,
                        'tvg_name'         => $attrs['tvg-name'] ?: null,
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

        Log::info('ImportM3uJob: Completed', array_merge(['source' => $this->source], $this->summary));

        return $this->summary;
    }

    /**
     * Parse a single #EXTINF line and return named attributes.
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

        // Extract key="value" pairs
        foreach (array_keys($attrs) as $key) {
            if ($key === 'name') {
                continue;
            }
            if (preg_match('/' . preg_quote($key, '/') . '="([^"]*)"/', $line, $m)) {
                $attrs[$key] = $m[1];
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
                yield fgets($handle);
            }
            fclose($handle);
        })();
    }
}
