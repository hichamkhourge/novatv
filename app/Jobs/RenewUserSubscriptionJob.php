<?php

namespace App\Jobs;

use App\Models\IptvUser;
use App\Models\M3uSource;
use App\Models\UserAutomationLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class RenewUserSubscriptionJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 1; // Don't retry automatically

    /**
     * Create a new job instance.
     */
    public function __construct(
        public IptvUser $user,
        public M3uSource $source
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = now();

        // Create automation log
        $log = UserAutomationLog::create([
            'iptv_user_id' => $this->user->id,
            'm3u_source_id' => $this->source->id,
            'status' => 'running',
            'started_at' => $startTime,
        ]);

        try {
            Log::info("Running renewal for user {$this->user->username} (ID: {$this->user->id})");

            // Decrypt provider credentials
            $providerUsername = $this->source->provider_username
                ? Crypt::decryptString($this->source->provider_username)
                : null;
            $providerPassword = $this->source->provider_password
                ? Crypt::decryptString($this->source->provider_password)
                : null;

            if (!$providerUsername || !$providerPassword) {
                throw new \Exception('Provider credentials not configured for this source');
            }

            // Get script path
            $scriptPath = $this->source->script_path ?: $this->getDefaultScriptPath($this->source->provider_type);

            if (!file_exists($scriptPath)) {
                throw new \Exception("Script not found at: {$scriptPath}");
            }

            // Get additional config
            $providerConfig = $this->source->provider_config ?? [];
            $packageId = $providerConfig['package_id'] ?? '384';

            // Build command
            $command = [
                'python3',
                $scriptPath,
                '--user-id', (string)$this->user->id,
                '--provider-username', $providerUsername,
                '--provider-password', $providerPassword,
                '--package-id', $packageId,
            ];

            Log::info("Executing command", ['command' => implode(' ', array_map(fn($arg) => $arg === $providerPassword ? '***' : $arg, $command))]);

            // Run the process
            $process = new Process($command);
            $process->setTimeout($this->timeout);
            $process->run();

            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();

            // Update log
            $log->update([
                'status' => $process->isSuccessful() ? 'success' : 'failed',
                'output' => $output,
                'error' => $errorOutput,
                'duration_seconds' => now()->diffInSeconds($startTime),
                'completed_at' => now(),
            ]);

            // Update source
            $this->source->update([
                'last_automation_run' => now(),
                'automation_status' => $process->isSuccessful()
                    ? 'Success'
                    : 'Failed: ' . substr($errorOutput, 0, 200),
            ]);

            if ($process->isSuccessful()) {
                Log::info("Renewal successful for user {$this->user->username}");

                // Optionally update user notes
                $this->user->update([
                    'notes' => ($this->user->notes ? $this->user->notes . "\n" : '')
                        . "Auto-renewed on " . now()->toDateTimeString()
                ]);
            } else {
                Log::error("Renewal failed for user {$this->user->username}", [
                    'output' => $output,
                    'error' => $errorOutput,
                ]);
                throw new ProcessFailedException($process);
            }

        } catch (\Exception $e) {
            Log::error("Renewal job failed for user {$this->user->username}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $log->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'duration_seconds' => now()->diffInSeconds($startTime),
                'completed_at' => now(),
            ]);

            $this->source->update([
                'last_automation_run' => now(),
                'automation_status' => 'Failed: ' . substr($e->getMessage(), 0, 200),
            ]);

            throw $e;
        }
    }

    /**
     * Get default script path for provider type
     */
    private function getDefaultScriptPath(string $providerType): string
    {
        $basePath = base_path('scripts');

        return match($providerType) {
            'ugeen' => $basePath . '/ugeen_renew_user.py',
            'zazy' => $basePath . '/zazy_renew_user.py',
            default => throw new \Exception("Unknown provider type: {$providerType}"),
        };
    }
}
