<?php

namespace App\Console\Commands;

use App\Models\M3uSource;
use Illuminate\Console\Command;

class ConfigureProxyMode extends Command
{
    protected $signature = 'iptv:proxy-mode
                            {mode : Enable or disable proxy mode (enable/disable/status)}
                            {--source= : Specific source ID to configure (optional, affects all if not provided)}';

    protected $description = 'Configure proxy mode for M3U sources (enable to route streams through your server)';

    public function handle()
    {
        $mode = strtolower($this->argument('mode'));
        $sourceId = $this->option('source');

        if (!in_array($mode, ['enable', 'disable', 'status'])) {
            $this->error('Invalid mode. Use: enable, disable, or status');
            return 1;
        }

        // Get sources to configure
        if ($sourceId) {
            $sources = M3uSource::where('id', $sourceId)->get();
            if ($sources->isEmpty()) {
                $this->error("M3U source with ID {$sourceId} not found.");
                return 1;
            }
        } else {
            $sources = M3uSource::all();
            if ($sources->isEmpty()) {
                $this->warn('No M3U sources found in the database.');
                return 0;
            }
        }

        if ($mode === 'status') {
            return $this->showStatus($sources);
        }

        // Enable or disable proxy mode
        $useDirectUrls = ($mode === 'disable'); // false = proxy enabled, true = direct URLs

        $this->info('Configuring proxy mode...');
        $this->newLine();

        foreach ($sources as $source) {
            $source->update(['use_direct_urls' => $useDirectUrls]);

            $proxyStatus = $useDirectUrls ? 'DISABLED (direct URLs)' : 'ENABLED (proxied)';
            $this->info("✓ Source #{$source->id}: {$source->name}");
            $this->line("  Proxy mode: {$proxyStatus}");
        }

        $this->newLine();
        $this->info('✓ Configuration complete!');
        $this->newLine();

        $this->showProxyInfo($useDirectUrls);

        return 0;
    }

    private function showStatus($sources)
    {
        $this->info('M3U Source Proxy Status:');
        $this->newLine();

        $enabledCount = 0;
        $disabledCount = 0;

        foreach ($sources as $source) {
            $isProxied = !$source->use_direct_urls;
            $status = $isProxied ? '<fg=green>ENABLED (proxied)</>' : '<fg=yellow>DISABLED (direct)</>';

            $this->line("Source #{$source->id}: {$source->name}");
            $this->line("  Type: {$source->source_type}");
            $this->line("  Proxy: {$status}");
            $this->line("  Active: " . ($source->is_active ? 'Yes' : 'No'));
            $this->newLine();

            if ($isProxied) {
                $enabledCount++;
            } else {
                $disabledCount++;
            }
        }

        $this->info("Summary: {$enabledCount} proxied, {$disabledCount} direct");

        return 0;
    }

    private function showProxyInfo(bool $directMode)
    {
        if ($directMode) {
            $this->comment('ℹ Direct URL mode:');
            $this->line('  • Streams point directly to the original server');
            $this->line('  • Lower server load');
            $this->line('  • May have compatibility issues with some players');
            $this->line('  • Connection limits harder to enforce');
        } else {
            $this->comment('ℹ Proxy mode (ENABLED):');
            $this->line('  • All streams route through your server');
            $this->line('  • Better compatibility with IPTV players (iboplayer, etc.)');
            $this->line('  • Connection tracking and limits enforced');
            $this->line('  • Higher server bandwidth usage');
            $this->line('  • Recommended for iboplayer and similar players');
        }

        $this->newLine();
        $this->comment('Test your setup:');
        $this->line('  1. Get M3U playlist: https://your-domain.com/get.php?username=USER&password=PASS');
        $this->line('  2. Or use Xtream API: https://your-domain.com/player_api.php?username=USER&password=PASS');
        $this->line('  3. Import into iboplayer and test playback');
    }
}
