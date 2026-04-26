<?php

namespace App\Console\Commands;

use App\Jobs\GenerateProviderAccountJob;
use App\Models\IptvAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewProviderAccounts extends Command
{
    protected $signature   = 'providers:renew {--provider= : Only renew accounts for this provider (e.g. zazy)}';
    protected $description = 'Re-generate Xtream credentials for all provider-managed IPTV accounts';

    public function handle(): int
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

        foreach ($accounts as $account) {
            $this->line("  → [{$account->provider}] {$account->username} (ID: {$account->id})");

            GenerateProviderAccountJob::dispatch($account->id, isRenewal: true)
                ->onQueue('default');

            Log::info('[providers:renew] Dispatched renewal job', [
                'account_id' => $account->id,
                'provider'   => $account->provider,
            ]);
        }

        $this->info('All renewal jobs dispatched to queue.');
        return self::SUCCESS;
    }
}
