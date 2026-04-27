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
     * Short timeout since we're just triggering the script (not waiting for completion).
     * The actual automation happens in the background via the Flask API.
     */
    public int $timeout = 60;

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

        // Trigger the automation script via Flask API (async approach)
        // The script will callback to our webhook when complete
        $result = match ($account->provider) {
            'zazy'  => $automation->generateZazyViaScript($account->id),
            default => ['success' => false, 'error' => "No automation for provider: {$account->provider}"],
        };

        if (! ($result['success'] ?? false)) {
            $error = $result['error'] ?? 'Unknown error from automation API';

            Log::error('[GenerateProviderAccountJob] Failed to trigger automation', [
                'account_id' => $account->id,
                'error'      => $error,
            ]);

            $account->update([
                'provider_status' => 'failed',
                'provider_error'  => $error,
            ]);

            return;
        }

        // Script triggered successfully - it will callback when complete
        Log::info('[GenerateProviderAccountJob] Automation script triggered successfully', [
            'account_id' => $account->id,
            'message'    => $result['message'] ?? 'Started',
        ]);

        // Account remains in 'pending' status until webhook updates it
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
