<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProviderAccountJob;
use App\Models\IptvAccount;
use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewProviderAccounts extends Command
{
    protected $signature   = 'providers:renew {--provider= : Only renew accounts for this provider (e.g. zazy, ugeen)}';
    protected $description = 'Re-generate Xtream credentials for all provider-managed IPTV accounts';

    public function handle(TelegramNotificationService $telegram): int
    {
        $onlyProvider = $this->option('provider');

        $query = IptvAccount::query()
            ->where('provider', '!=', 'manual')
            ->where('status', 'active');

        if ($onlyProvider) {
            $query->where('provider', $onlyProvider);
        }

        $accounts = $query->get();

        if ($accounts->isEmpty()) {
            $this->info('No provider-managed accounts to renew.');
            return self::SUCCESS;
        }

        $this->info("Renewing credentials for {$accounts->count()} account(s)...");

        $dispatched = 0;
        foreach ($accounts as $account) {
            $this->line("  → [{$account->provider}] {$account->username} (ID: {$account->id})");

            GenerateProviderAccountJob::dispatch($account->id, isRenewal: true)
                ->onQueue('default');

            $dispatched++;

            Log::info('[providers:renew] Dispatched renewal job', [
                'account_id' => $account->id,
                'provider'   => $account->provider,
            ]);
        }

        $this->info("All {$dispatched} renewal jobs dispatched to queue.");

        // Log the renewal batch start
        Log::info('[providers:renew] Renewal batch started', [
            'total_accounts' => $dispatched,
            'provider_filter' => $onlyProvider ?? 'all',
        ]);

        // Note: Summary notification will be sent after jobs complete
        // You may want to create a separate command to check renewal results later

        return self::SUCCESS;
    }
}
