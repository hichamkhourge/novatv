<?php

namespace App\Services;

use App\Models\IptvUser;
use App\Models\M3uSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

class TuliproxService
{
    protected string $configPath;
    protected string $userYmlPath;
    protected string $sourceYmlPath;
    protected string $tuliproxHost;
    protected int $tuliproxPort;

    public function __construct()
    {
        $this->configPath = env('TULIPROX_CONFIG_PATH', '/opt/tuliprox/config');
        $this->userYmlPath = $this->configPath . '/user.yml';
        $this->sourceYmlPath = $this->configPath . '/source.yml';
        $this->tuliproxHost = env('TULIPROX_HOST', 'tuliprox');
        $this->tuliproxPort = (int) env('TULIPROX_PORT', 8901);
    }

    /**
     * Add a single user to user.yml
     */
    public function addUser(IptvUser $user): bool
    {
        try {
            if (!$user->is_active) {
                Log::info("Tuliprox: Skipping inactive user {$user->username}");
                return true;
            }

            $users = $this->readUserYml();

            // Add or update the user
            $users[$user->username] = [
                'username' => $user->username,
                'password' => $user->password,
                'token' => $user->username, // token same as username
                'targets' => ['server1'],
            ];

            $this->writeUserYml($users);

            Log::info("Tuliprox: Added/updated user {$user->username}");
            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to add user {$user->username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a user from user.yml
     */
    public function removeUser(IptvUser $user): bool
    {
        try {
            $users = $this->readUserYml();

            if (isset($users[$user->username])) {
                unset($users[$user->username]);
                $this->writeUserYml($users);
                Log::info("Tuliprox: Removed user {$user->username}");
            }

            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to remove user {$user->username}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync all active users to user.yml
     */
    public function syncAllUsers(): bool
    {
        try {
            $activeUsers = IptvUser::where('is_active', true)->get();

            $users = [];
            foreach ($activeUsers as $user) {
                $users[$user->username] = [
                    'username' => $user->username,
                    'password' => $user->password,
                    'token' => $user->username,
                    'targets' => ['server1'],
                ];
            }

            $this->writeUserYml($users);

            Log::info("Tuliprox: Synced " . count($users) . " active users");
            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to sync all users: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Sync M3U sources to source.yml
     */
    public function syncSources(Collection $sources = null): bool
    {
        try {
            if ($sources === null) {
                $sources = M3uSource::where('is_active', true)->get();
            }

            $sourceConfig = [];

            foreach ($sources as $source) {
                // Generate a safe key from the source name
                $sourceKey = $this->generateSourceKey($source->name);

                // Determine the input URL/path
                $inputUrl = $source->source_type === 'file'
                    ? $source->file_path
                    : $source->url;

                if (!$inputUrl) {
                    Log::warning("Tuliprox: Skipping source {$source->name} - no URL or path");
                    continue;
                }

                $sourceConfig[$sourceKey] = [
                    'enabled' => $source->is_active,
                    'input' => [
                        'type' => 'xtream',
                        'url' => $inputUrl,
                    ],
                    'filter' => [
                        'group' => 'Group ~ ".*"', // Match all groups
                    ],
                    'output' => [
                        'type' => 'xtream',
                        'name' => $source->name,
                    ],
                ];

                // Add provider credentials if available
                if ($source->provider_username && $source->provider_password) {
                    $sourceConfig[$sourceKey]['input']['username'] = $source->provider_username;
                    $sourceConfig[$sourceKey]['input']['password'] = $source->provider_password;
                }
            }

            $this->writeSourceYml($sourceConfig);

            Log::info("Tuliprox: Synced " . count($sourceConfig) . " sources");
            return true;
        } catch (\Exception $e) {
            Log::error("Tuliprox: Failed to sync sources: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate a safe source key from name
     */
    protected function generateSourceKey(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $name));
    }

    /**
     * Read user.yml file
     */
    protected function readUserYml(): array
    {
        if (!file_exists($this->userYmlPath)) {
            return [];
        }

        $content = file_get_contents($this->userYmlPath);
        $parsed = Yaml::parse($content);

        return $parsed ?? [];
    }

    /**
     * Write user.yml file
     */
    protected function writeUserYml(array $users): void
    {
        $this->ensureConfigDirectory();

        $yaml = Yaml::dump($users, 4, 2);
        file_put_contents($this->userYmlPath, $yaml);
    }

    /**
     * Write source.yml file
     */
    protected function writeSourceYml(array $sources): void
    {
        $this->ensureConfigDirectory();

        $yaml = Yaml::dump($sources, 6, 2);
        file_put_contents($this->sourceYmlPath, $yaml);
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
            $url = "http://{$this->tuliproxHost}:{$this->tuliproxPort}/api/v1/playlist/stats";

            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->get($url);

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
