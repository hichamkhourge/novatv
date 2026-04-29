<?php

namespace App\Services;

use App\Models\IptvAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotificationService
{
    protected string $botToken;
    protected string $chatId;
    protected bool $enabled;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', '');
        $this->chatId = config('services.telegram.chat_id', '');
        $this->enabled = !empty($this->botToken) && !empty($this->chatId);
    }

    /**
     * Send notification when a new Ugeen account is activated.
     */
    public function notifyAccountActivated(IptvAccount $account): void
    {
        if (!$this->enabled) {
            Log::debug('Telegram notifications disabled - missing configuration');
            return;
        }

        $message = $this->formatActivationMessage($account);
        $this->sendMessage($message);
    }

    /**
     * Send notification when a Ugeen account is successfully renewed.
     */
    public function notifyAccountRenewed(IptvAccount $account): void
    {
        if (!$this->enabled) {
            Log::debug('Telegram notifications disabled - missing configuration');
            return;
        }

        $message = $this->formatRenewalMessage($account);
        $this->sendMessage($message);
    }

    /**
     * Send notification when a Ugeen account renewal fails.
     */
    public function notifyRenewalFailed(IptvAccount $account, string $error): void
    {
        if (!$this->enabled) {
            Log::debug('Telegram notifications disabled - missing configuration');
            return;
        }

        $message = $this->formatFailureMessage($account, $error);
        $this->sendMessage($message);
    }

    /**
     * Send summary notification for batch renewals.
     */
    public function notifyRenewalSummary(int $total, int $successful, int $failed): void
    {
        if (!$this->enabled) {
            Log::debug('Telegram notifications disabled - missing configuration');
            return;
        }

        $emoji = $failed === 0 ? '✅' : '⚠️';
        $message = "{$emoji} *Ugeen Daily Renewal Summary*\n\n";
        $message .= "Total accounts: {$total}\n";
        $message .= "✅ Successful: {$successful}\n";

        if ($failed > 0) {
            $message .= "❌ Failed: {$failed}\n";
        }

        $message .= "\n_" . now()->format('Y-m-d H:i:s') . "_";

        $this->sendMessage($message);
    }

    /**
     * Format activation message.
     */
    protected function formatActivationMessage(IptvAccount $account): string
    {
        $message = "🎉 *New Ugeen Account Activated*\n\n";
        $message .= "👤 Username: `{$account->username}`\n";
        $message .= "📅 Expires: " . $account->expires_at->format('Y-m-d H:i') . "\n";
        $message .= "📺 Max Connections: {$account->max_connections}\n";
        $message .= "🔗 Status: Active\n";

        if ($account->m3u_source) {
            $message .= "📡 Source: {$account->m3u_source->name}\n";
        }

        $message .= "\n_Created: " . now()->format('Y-m-d H:i:s') . "_";

        return $message;
    }

    /**
     * Format renewal message.
     */
    protected function formatRenewalMessage(IptvAccount $account): string
    {
        $message = "🔄 *Ugeen Account Renewed*\n\n";
        $message .= "👤 Username: `{$account->username}`\n";
        $message .= "📅 New Expiry: " . $account->expires_at->format('Y-m-d H:i') . "\n";
        $message .= "📺 Max Connections: {$account->max_connections}\n";
        $message .= "✅ Status: Active\n";

        $message .= "\n_Renewed: " . now()->format('Y-m-d H:i:s') . "_";

        return $message;
    }

    /**
     * Format failure message.
     */
    protected function formatFailureMessage(IptvAccount $account, string $error): string
    {
        $message = "❌ *Ugeen Account Renewal Failed*\n\n";
        $message .= "👤 Username: `{$account->username}`\n";
        $message .= "📅 Expires: " . $account->expires_at->format('Y-m-d H:i') . "\n";
        $message .= "⚠️ Error: " . $this->escapeMarkdown($error) . "\n";

        $message .= "\n_Failed: " . now()->format('Y-m-d H:i:s') . "_";

        return $message;
    }

    /**
     * Send message via Telegram Bot API.
     */
    protected function sendMessage(string $message): void
    {
        try {
            $response = Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
                'chat_id' => $this->chatId,
                'text' => $message,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            if (!$response->successful()) {
                Log::error('Failed to send Telegram message', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            } else {
                Log::info('Telegram notification sent successfully');
            }
        } catch (\Exception $e) {
            Log::error('Exception sending Telegram message', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Escape special characters for Telegram Markdown.
     */
    protected function escapeMarkdown(string $text): string
    {
        // Escape special Markdown characters
        $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];

        foreach ($specialChars as $char) {
            $text = str_replace($char, '\\' . $char, $text);
        }

        return $text;
    }
}
