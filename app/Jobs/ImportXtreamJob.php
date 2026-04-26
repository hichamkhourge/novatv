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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Import channels from an Xtream Codes API endpoint.
 *
 * Calls:
 *   GET /player_api.php?username=X&password=Y&action=get_live_categories
 *   GET /player_api.php?username=X&password=Y&action=get_live_streams
 *   (optionally VOD & series too)
 *
 * Stream URL format: http://host:port/username/password/stream_id.ts
 */
class ImportXtreamJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes
    public int $tries   = 1;

    public array $summary = ['created' => 0, 'skipped' => 0, 'errors' => 0];

    public function __construct(
        public readonly int $sourceId,
    ) {}

    public function handle(): void
    {
        $m3uSource = M3uSource::find($this->sourceId);
        if (! $m3uSource) {
            Log::error('ImportXtreamJob: Source not found', ['sourceId' => $this->sourceId]);
            return;
        }

        $host     = rtrim($m3uSource->xtream_host, '/');
        $username = $m3uSource->xtream_username;
        $password = $m3uSource->xtream_password;

        if (! $host || ! $username || ! $password) {
            $m3uSource->update(['status' => 'error', 'error_message' => 'Xtream host/username/password not configured.']);
            return;
        }

        // Prevent recursive/self-hosted imports.
        // If the Xtream host points to this panel domain, we end up importing
        // our own proxied API output instead of the real upstream provider.
        if ($this->isSelfHostedXtream($host)) {
            $appUrl = (string) config('app.url', '');
            $message = "Invalid Xtream host: {$host}. Use upstream provider host, not this panel URL ({$appUrl}).";

            $m3uSource->update(['status' => 'error', 'error_message' => $message]);

            Log::error('ImportXtreamJob: blocked self-hosted xtream source', [
                'source_id'   => $this->sourceId,
                'source_name' => $m3uSource->name,
                'xtream_host' => $host,
                'app_url'     => $appUrl,
            ]);

            return;
        }

        $m3uSource->update(['status' => 'syncing', 'error_message' => null]);

        // Excluded groups (lowercase for case-insensitive matching)
        $excludedGroups = [];
        if (! empty($m3uSource->excluded_groups)) {
            $raw            = $m3uSource->excluded_groups;
            $excludedGroups = is_array($raw) ? $raw : (json_decode($raw, true) ?? []);
            $excludedGroups = array_map('strtolower', $excludedGroups);
        }

        // Which stream types to import
        $streamTypes = ['live'];
        if (! empty($m3uSource->xtream_stream_types)) {
            $raw         = $m3uSource->xtream_stream_types;
            $streamTypes = is_array($raw) ? $raw : (json_decode($raw, true) ?? ['live']);
        }

        try {
            // Delete existing channels for a clean re-import
            Channel::where('m3u_source_id', $this->sourceId)->delete();

            $sortOrder = 0;

            foreach ($streamTypes as $type) {
                $this->importType($host, $username, $password, $type, $m3uSource, $excludedGroups, $sortOrder);
            }

            // Update stats
            $channelsCount = Channel::where('m3u_source_id', $this->sourceId)->count();
            $m3uSource->update([
                'status'         => 'idle',
                'channels_count' => $channelsCount,
                'last_synced_at' => now(),
                'error_message'  => null,
            ]);

            Log::info('ImportXtreamJob: Completed', ['sourceId' => $this->sourceId, 'summary' => $this->summary]);
        } catch (\Throwable $e) {
            $m3uSource->update(['status' => 'error', 'error_message' => $e->getMessage()]);
            Log::error('ImportXtreamJob: Failed', ['sourceId' => $this->sourceId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function importType(
        string   $host,
        string   $username,
        string   $password,
        string   $type,           // live | vod | series
        M3uSource $m3uSource,
        array    $excludedGroups,
        int      &$sortOrder,
    ): void {
        $apiBase = "{$host}/player_api.php?username={$username}&password={$password}";

        // ── 1. Fetch categories ───────────────────────────────────────────────
        $catAction  = match ($type) {
            'vod'    => 'get_vod_categories',
            'series' => 'get_series_categories',
            default  => 'get_live_categories',
        };

        Log::info("ImportXtreamJob: Fetching {$catAction}");
        $catResponse = Http::timeout(60)
            ->withUserAgent('Mozilla/5.0 (compatible; IPTVImporter/1.0)')
            ->get("{$apiBase}&action={$catAction}");

        if (! $catResponse->successful()) {
            Log::warning("ImportXtreamJob: Failed to fetch categories", ['action' => $catAction, 'status' => $catResponse->status()]);
            return;
        }

        $categories = $catResponse->json();
        if (! is_array($categories)) {
            Log::warning("ImportXtreamJob: Categories response is not an array");
            return;
        }

        // Build category_id → ChannelGroup model map
        $categoryMap = [];  // category_id (string) → ChannelGroup::id (int)
        $groupCache  = [];  // name → id

        foreach ($categories as $cat) {
            $catId   = (string) ($cat['category_id'] ?? '');
            $catName = trim($cat['category_name'] ?? 'Uncategorized');

            if ($catName === '') {
                $catName = 'Uncategorized';
            }

            if (! isset($groupCache[$catName])) {
                // Scope by m3u_source_id so two sources can have same-named categories
                // Use a source-prefixed slug to avoid the global unique constraint collision
                $slug  = Str::slug("s{$this->sourceId}-{$catName}");
                $group = ChannelGroup::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $catName, 'sort_order' => 0, 'is_active' => true],
                );
                $groupCache[$catName] = $group->id;
            }

            if ($catId !== '') {
                $categoryMap[$catId] = $groupCache[$catName];
            }
        }

        // Build a reverse map: ChannelGroup DB id → group name (for exclusion checks)
        $idToName = array_flip($groupCache);  // id (int) → name (string)

        // ── 2. Fetch streams ──────────────────────────────────────────────────
        $streamAction = match ($type) {
            'vod'    => 'get_vod_streams',
            'series' => 'get_series',
            default  => 'get_live_streams',
        };

        Log::info("ImportXtreamJob: Fetching {$streamAction}");
        $streamResponse = Http::timeout(120)
            ->withUserAgent('Mozilla/5.0 (compatible; IPTVImporter/1.0)')
            ->get("{$apiBase}&action={$streamAction}");

        if (! $streamResponse->successful()) {
            Log::warning("ImportXtreamJob: Failed to fetch streams", ['action' => $streamAction, 'status' => $streamResponse->status()]);
            return;
        }

        $streams = $streamResponse->json();
        if (! is_array($streams)) {
            Log::warning("ImportXtreamJob: Streams response is not an array");
            return;
        }

        Log::info("ImportXtreamJob: Processing " . count($streams) . " {$type} streams");

        // ── 3. Batch insert streams ───────────────────────────────────────────
        $batch     = [];
        $batchSize = 500;
        $now       = now()->toDateTimeString();

        $ext = match ($type) {
            'vod'    => 'mp4',
            'series' => 'mkv',
            default  => 'ts',
        };

        foreach ($streams as $stream) {
            $streamId   = $stream['stream_id'] ?? ($stream['series_id'] ?? null);
            $name       = trim($stream['name'] ?? '');
            $categoryId = (string) ($stream['category_id'] ?? '');
            $icon       = $stream['stream_icon'] ?? ($stream['cover'] ?? null);

            if (! $streamId || ! $name) {
                $this->summary['skipped']++;
                continue;
            }

            // Resolve group — if category_id not found, this stream has no valid category
            if (! isset($categoryMap[$categoryId])) {
                // Unknown category → skip entirely (these are usually VOD/series bleed-ins)
                $this->summary['skipped']++;
                continue;
            }

            $groupId   = $categoryMap[$categoryId];
            $groupName = $idToName[$groupId] ?? '';

            // Exclusion check with prefix/substring matching:
            // "24/7" in excluded_groups will match "24/7 Tv Series", "24/7 Action", etc.
            if (! empty($excludedGroups)) {
                $groupLower = strtolower($groupName);
                $excluded   = false;
                foreach ($excludedGroups as $pattern) {
                    // Exact match OR the group name starts with the pattern
                    if ($groupLower === $pattern || str_starts_with($groupLower, $pattern)) {
                        $excluded = true;
                        break;
                    }
                }
                if ($excluded) {
                    $this->summary['skipped']++;
                    continue;
                }
            }

            // Build the stream URL.
            // For live streams: use the bare {host}/{user}/{pass}/{stream_id} format.
            // Xtream Codes servers detect content type from the stream itself.
            // Some providers (e.g. ugeen) actively reject a .ts suffix despite the API
            // returning container_extension = "ts", so we never append it for live streams.
            // For VOD/series, the extension is required (mp4, mkv, etc.).
            if ($type === 'live') {
                $streamUrl = "{$host}/{$username}/{$password}/{$streamId}";
            } else {
                $containerExt = trim($stream['container_extension'] ?? $ext);
                $streamUrl    = "{$host}/{$username}/{$password}/{$streamId}.{$containerExt}";
            }

            // Skip base64-encoded logos (data: URIs) — they're too large for DB storage
            $logoUrl = ($icon && ! str_starts_with($icon, 'data:')) ? $icon : null;

            $batch[] = [
                'channel_group_id' => $groupId,
                'm3u_source_id'    => $this->sourceId,
                'stream_url'       => $streamUrl,
                'name'             => $name,
                'logo_url'         => $logoUrl,
                'tvg_id'           => (string) $streamId,
                'tvg_name'         => $name,
                'sort_order'       => $sortOrder++,
                'is_active'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];

            $this->summary['created']++;

            if (count($batch) >= $batchSize) {
                Channel::insert($batch);
                $batch = [];
            }
        }

        // Flush remaining
        if (! empty($batch)) {
            Channel::insert($batch);
        }
    }

    public function failed(\Throwable $exception): void
    {
        M3uSource::where('id', $this->sourceId)->update([
            'status'        => 'error',
            'error_message' => $exception->getMessage(),
        ]);
        Log::error('ImportXtreamJob: Failed', ['sourceId' => $this->sourceId, 'error' => $exception->getMessage()]);
    }

    private function isSelfHostedXtream(string $host): bool
    {
        $xtreamHost = $this->extractHost($host);
        $appHost = $this->extractHost((string) config('app.url', ''));

        if ($xtreamHost === '' || $appHost === '') {
            return false;
        }

        return $xtreamHost === $appHost;
    }

    private function extractHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '://')) {
            $value = 'http://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        return strtolower((string) ($host ?? ''));
    }
}
