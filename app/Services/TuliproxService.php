<?php

namespace App\Services;

use App\Models\IptvUser;
use App\Models\M3uSource;
use App\Models\TuliproxServer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class TuliproxService
{
    protected string $configPath;
    protected string $userYmlPath;
    protected string $sourceYmlPath;
    protected string $apiProxyYmlPath;
    protected ChannelFilterBuilder $filterBuilder;

    public function __construct(ChannelFilterBuilder $filterBuilder)
    {
        $this->configPath = env('TULIPROX_CONFIG_PATH', '/opt/tuliprox/config');
        $this->userYmlPath = $this->configPath . '/user.yml';
        $this->sourceYmlPath = $this->configPath . '/source.yml';
        $this->apiProxyYmlPath = $this->configPath . '/api-proxy.yml';
        $this->filterBuilder = $filterBuilder;
    }

    /**
     * Sync all configuration files
     */
    public function syncAll(): bool
    {
        try {
            $this->syncSources();
            $this->syncUsers();
            $this->syncApiProxy();

            Log::info('Tuliprox: All configuration files synced successfully');
            return true;
        } catch (\Exception $e) {
            Log::error('Tuliprox: Failed to sync all files: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync source.yml - M3U sources with inputs and targets
     */
    public function syncSources(): bool
    {
        try {
            $sources = M3uSource::where('is_active', true)->get();

            if ($sources->isEmpty()) {
                Log::warning('Tuliprox: No active M3U sources found');
                $this->writeSourceYml([]);
                return true;
            }

            $sourcesArray = [];

            foreach ($sources as $source) {
                $targetName = $source->target_name;

                // Determine input URL
                $inputUrl = $source->source_type === 'file'
                    ? $source->file_path
                    : $source->url;

                if (!$inputUrl) {
                    Log::warning("Tuliprox: Skipping source {$source->name} - no URL or path");
                    continue;
                }

                // Build input configuration
                $input = [
                    'name' => $targetName,
                    'type' => 'm3u',
                    'url' => $inputUrl,
                ];

                // Add provider credentials if available (decrypt them)
                if ($source->provider_username && $source->provider_password) {
                    try {
                        $input['username'] = Crypt::decryptString($source->provider_username);
                        $input['password'] = Crypt::decryptString($source->provider_password);
                    } catch (\Exception $e) {
                        Log::warning("Tuliprox: Failed to decrypt credentials for source {$source->name}");
                    }
                }

                // Build target configuration
                $target = [
                    'name' => $targetName,
                    'enabled' => true,
                    'filter' => 'Group ~ ".*"', // Match all at source level, filter per-user
                    'output' => [
                        ['type' => 'xtream'],
                    ],
                ];

                // Add to sources array
                $sourcesArray[] = [
                    'inputs' => [$input],
                    'targets' => [$target],
                ];
            }

            $this->writeSourceYml(['sources' => $sourcesArray]);

            Log::info("Tuliprox: Synced " . count($sourcesArray) . " sources to source.yml");
            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to sync sources: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync user.yml - User credentials and target assignments
     */
    public function syncUsers(): bool
    {
        try {
            $users = IptvUser::where('is_active', true)
                ->with('m3uSource')
                ->get();

            $userConfig = [];

            foreach ($users as $user) {
                if (!$user->m3uSource) {
                    Log::warning("Tuliprox: Skipping user {$user->username} - no M3U source assigned");
                    continue;
                }

                $targetName = $user->m3uSource->target_name;

                $userConfig[$user->username] = [
                    'username' => $user->username,
                    'password' => $user->password,
                    'token' => $user->username,
                    'targets' => [$targetName],
                ];
            }

            $this->writeUserYml($userConfig);

            Log::info("Tuliprox: Synced " . count($userConfig) . " users to user.yml");
            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to sync users: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync api-proxy.yml - Servers and user credentials with per-user filters
     */
    public function syncApiProxy(): bool
    {
        try {
            // Get all active servers
            $servers = TuliproxServer::where('is_active', true)->get();

            if ($servers->isEmpty()) {
                Log::warning('Tuliprox: No active servers found');
                $this->writeApiProxyYml(['server' => [], 'user' => []]);
                return true;
            }

            // Build server configuration
            $serverConfig = $servers->map(function ($server) {
                return [
                    'name' => $server->name,
                    'protocol' => $server->protocol,
                    'host' => $server->host,
                    'port' => $server->port,
                    'timezone' => $server->timezone,
                    'message' => $server->message ?? 'Welcome to Tuliprox',
                ];
            })->toArray();

            // Build user configuration grouped by target
            $users = IptvUser::where('is_active', true)
                ->with(['m3uSource', 'tuliproxServer', 'channels'])
                ->get();

            // Group users by their target (M3U source)
            $usersByTarget = $users->groupBy(function ($user) {
                return $user->m3uSource?->target_name;
            });

            $userConfig = [];

            foreach ($usersByTarget as $targetName => $targetUsers) {
                if (!$targetName) {
                    continue;
                }

                $credentials = [];

                foreach ($targetUsers as $user) {
                    // Get server name (use default if not assigned)
                    $serverName = $user->tuliproxServer?->name
                        ?? $servers->where('is_default', true)->first()?->name
                        ?? $servers->first()?->name;

                    if (!$serverName) {
                        Log::warning("Tuliprox: No server available for user {$user->username}");
                        continue;
                    }

                    // Build user filter from channel selection
                    $filter = $this->filterBuilder->buildForUser($user);

                    // Calculate expiration timestamp
                    $expDate = $user->expires_at ? $user->expires_at->timestamp : 0;

                    $credentials[] = [
                        'username' => $user->username,
                        'password' => $user->password,
                        'token' => $user->username,
                        'proxy' => 'reverse',
                        'server' => $serverName,
                        'exp_date' => $expDate,
                        'max_connections' => $user->max_connections ?? 1,
                        'status' => $user->isValid() ? 'Active' : 'Disabled',
                        'filter' => $filter,
                        'ui_enabled' => true,
                    ];
                }

                if (!empty($credentials)) {
                    $userConfig[] = [
                        'target' => $targetName,
                        'credentials' => $credentials,
                    ];
                }
            }

            $this->writeApiProxyYml([
                'server' => $serverConfig,
                'user' => $userConfig,
            ]);

            Log::info("Tuliprox: Synced api-proxy.yml with " . count($serverConfig) . " servers and " . $users->count() . " users");
            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to sync api-proxy: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Write source.yml file
     */
    protected function writeSourceYml(array $data): void
    {
        $this->ensureConfigDirectory();
        $yaml = Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($this->sourceYmlPath, $yaml);
    }

    /**
     * Write user.yml file
     */
    protected function writeUserYml(array $data): void
    {
        $this->ensureConfigDirectory();
        $yaml = Yaml::dump($data, 4, 2);
        file_put_contents($this->userYmlPath, $yaml);
    }

    /**
     * Write api-proxy.yml file
     */
    protected function writeApiProxyYml(array $data): void
    {
        $this->ensureConfigDirectory();
        $yaml = Yaml::dump($data, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        file_put_contents($this->apiProxyYmlPath, $yaml);
    }

    /**
     * Ensure the config directory exists
     */
    protected function ensureConfigDirectory(): void
    {
        if (!is_dir($this->configPath)) {
            mkdir($this->configPath, 0755, true);
        }
    }

    /**
     * Get tuliprox API stats
     */
    public function getStats(): ?array
    {
        try {
            $host = env('TULIPROX_HOST', 'tuliprox');
            $port = env('TULIPROX_PORT', 8901);
            $url = "http://{$host}:{$port}/api/v1/playlist/stats";

            $response = \Illuminate\Support\Facades\Http::timeout(5)->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("Tuliprox: API returned status " . $response->status());
            return null;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to fetch stats: " . $e->getMessage());
            return null;
        }
    }
}
