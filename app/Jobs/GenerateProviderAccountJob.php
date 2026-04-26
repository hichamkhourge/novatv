<?php

namespace App\Jobs;

use App\Models\IptvAccount;
use App\Models\M3uSource;
use App\Services\ProviderAutomationService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateProviderAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Allow up to 12 minutes before the job times out.
     * Selenium + 2captcha can take up to 8–10 minutes.
     */
    public int $timeout = 720;

    /**
     * Do not retry on failure — each run creates a new Zazy trial account.
     * Retrying would waste captcha credits.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $accountId,
        public readonly bool $isRenewal = false,
    ) {}

    public function handle(ProviderAutomationService $automation): void
    {
        $account = IptvAccount::find($this->accountId);

        if (! $account) {
            Log::warning('[GenerateProviderAccountJob] Account not found', ['id' => $this->accountId]);
            return;
        }

        Log::info('[GenerateProviderAccountJob] Starting', [
            'account_id' => $account->id,
            'provider'   => $account->provider,
            'renewal'    => $this->isRenewal,
        ]);

        // Mark as pending so the UI shows a spinner
        $account->update([
            'provider_status' => 'pending',
            'provider_error'  => null,
        ]);

        // Call the appropriate automation endpoint
        $result = match ($account->provider) {
            'zazy'  => $automation->generateZazy(),
            default => ['success' => false, 'error' => "No automation for provider: {$account->provider}"],
        };

        if (! ($result['success'] ?? false)) {
            $error = $result['error'] ?? 'Unknown error from automation API';

            Log::error('[GenerateProviderAccountJob] Generation failed', [
                'account_id' => $account->id,
                'error'      => $error,
            ]);

            $account->update([
                'provider_status' => 'failed',
                'provider_error'  => $error,
            ]);

            return;
        }

        // ── Upsert the M3uSource with the new Xtream credentials ─────────────
        $host     = $result['xtream_host'] ?? null;
        $username = $result['xtream_username'];
        $password = $result['xtream_password'];

        // Default host to the known Zazy host if the script didn't extract it
        if (! $host && $account->provider === 'zazy') {
            $host = config('services.providers.zazy_host', 'http://live.zazytv.com');
        }

        $sourceName = ucfirst($account->provider) . ' — ' . $account->username;

        if ($account->m3u_source_id) {
            // Update existing source
            $source = M3uSource::find($account->m3u_source_id);
            if ($source) {
                $source->update([
                    'xtream_host'     => $host,
                    'xtream_username' => $username,
                    'xtream_password' => $password,
                    'status'          => 'idle',
                    'error_message'   => null,
                ]);

                Log::info('[GenerateProviderAccountJob] Updated existing M3uSource', ['source_id' => $source->id]);
            }
        } else {
            // Create a brand-new Xtream source and link it to the account
            $source = M3uSource::create([
                'name'             => $sourceName,
                'source_type'      => 'xtream',
                'xtream_host'      => $host,
                'xtream_username'  => $username,
                'xtream_password'  => $password,
                'xtream_stream_types' => ['live'],
                'status'           => 'idle',
                'is_active'        => true,
                'channels_count'   => 0,
            ]);

            $account->update(['m3u_source_id' => $source->id]);

            Log::info('[GenerateProviderAccountJob] Created new M3uSource', ['source_id' => $source->id]);
        }

        // Mark account as done and record sync time
        $account->update([
            'provider_status'    => 'done',
            'provider_error'     => null,
            'provider_synced_at' => now(),
        ]);

        // Kick off channel import now that we have fresh credentials
        ImportXtreamJob::dispatch($source->id);

        Log::info('[GenerateProviderAccountJob] Completed successfully', [
            'account_id' => $account->id,
            'source_id'  => $source->id,
        ]);
    }

    /**
     * Handle job failure (timeout, exception, etc.).
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[GenerateProviderAccountJob] Job failed with exception', [
            'account_id' => $this->accountId,
            'error'      => $exception->getMessage(),
        ]);

        $account = IptvAccount::find($this->accountId);
        $account?->update([
            'provider_status' => 'failed',
            'provider_error'  => $exception->getMessage(),
        ]);
    }
}
