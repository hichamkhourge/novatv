<?php

namespace App\Http\Controllers;

use App\Models\IptvAccount;
use App\Models\M3uSource;
use App\Jobs\ImportXtreamJob;
use App\Services\TelegramNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UgeenWebhookController extends Controller
{
    protected TelegramNotificationService $telegram;

    public function __construct(TelegramNotificationService $telegram)
    {
        $this->telegram = $telegram;
    }

    /**
     * Handle webhook callback from Ugeen automation script.
     *
     * Expected payload:
     * {
     *     "user_id": 123,
     *     "status": "success|failed",
     *     "username": "...",
     *     "password": "...",
     *     "host": "http://ugeen.live",
     *     "m3u_url": "...",
     *     "error": "...",
     *     "is_renewal": false,
     *     "timestamp": "2024-01-01T00:00:00Z"
     * }
     */
    public function handleCallback(Request $request): JsonResponse
    {
        // Verify authentication token
        $this->verifyWebhookToken($request);

        // Validate request payload
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:iptv_accounts,id',
            'status' => 'required|in:success,failed',
            'username' => 'required_if:status,success|string|max:255',
            'password' => 'required_if:status,success|string|max:255',
            'host' => 'required_if:status,success|url',
            'm3u_url' => 'nullable|url',
            'error' => 'required_if:status,failed|string',
            'is_renewal' => 'nullable|boolean',
            'timestamp' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            Log::warning('Ugeen webhook validation failed', [
                'errors' => $validator->errors()->toArray(),
                'payload' => $request->except(['password'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $validator->validated();
        $isRenewal = $data['is_renewal'] ?? false;

        try {
            // Find the IPTV account
            $account = IptvAccount::findOrFail($data['user_id']);

            Log::info('Processing Ugeen webhook', [
                'account_id' => $account->id,
                'username' => $account->username,
                'status' => $data['status'],
                'is_renewal' => $isRenewal
            ]);

            if ($data['status'] === 'success') {
                $this->handleSuccessCallback($account, $data, $isRenewal);

                return response()->json([
                    'success' => true,
                    'message' => $isRenewal ? 'Account renewed successfully' : 'Credentials created successfully',
                    'account_id' => $account->id
                ], 200);
            } else {
                $this->handleFailureCallback($account, $data, $isRenewal);

                return response()->json([
                    'success' => false,
                    'message' => 'Automation failed',
                    'account_id' => $account->id,
                    'error' => $data['error']
                ], 200); // Still return 200 to acknowledge receipt
            }
        } catch (\Exception $e) {
            Log::error('Error processing Ugeen webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->except(['password'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle successful automation callback.
     */
    protected function handleSuccessCallback(IptvAccount $account, array $data, bool $isRenewal): void
    {
        Log::info('Handling successful Ugeen automation', [
            'account_id' => $account->id,
            'username' => $data['username'],
            'is_renewal' => $isRenewal
        ]);

        if ($isRenewal && $account->m3u_source_id) {
            // Update existing M3U source credentials
            $m3uSource = M3uSource::find($account->m3u_source_id);
            if ($m3uSource) {
                $m3uSource->update([
                    'xtream_username' => $data['username'],
                    'xtream_password' => $data['password'],
                    'xtream_host' => $data['host'],
                    'status' => 'active',
                ]);

                Log::info('M3U source credentials renewed', [
                    'source_id' => $m3uSource->id,
                    'account_id' => $account->id
                ]);
            }
        } else {
            // Create a fresh M3U source per Ugeen account from the returned credentials.
            $m3uSource = M3uSource::create([
                'name' => "Ugeen - {$account->username}",
                'source_type' => 'xtream',
                'xtream_host' => $data['host'],
                'xtream_username' => $data['username'],
                'xtream_password' => $data['password'],
                'xtream_stream_types' => ['live', 'movie', 'series'],
                'provider_type' => 'ugeen',
                'status' => 'active',
                'is_active' => true,
                'excluded_groups' => ['24/7'], // Exclude VOD groups
            ]);

            Log::info('M3U source created', [
                'source_id' => $m3uSource->id,
                'account_id' => $account->id
            ]);

            // Update IPTV account with source
            $account->update([
                'm3u_source_id' => $m3uSource->id,
            ]);
        }

        // Update IPTV account provider status
        $account->update([
            'provider_status' => 'done',
            'provider_error' => null,
            'provider_synced_at' => now(),
        ]);

        Log::info('IPTV account updated', [
            'account_id' => $account->id,
            'source_id' => $account->m3u_source_id
        ]);

        // Dispatch job to import channels from Xtream API (only for new accounts or if needed)
        if (!$isRenewal || !$account->m3u_source->channels()->exists()) {
            ImportXtreamJob::dispatch($account->m3u_source_id);

            Log::info('ImportXtreamJob dispatched', [
                'source_id' => $account->m3u_source_id,
                'account_id' => $account->id
            ]);
        }

        // Send Telegram notification
        try {
            if ($isRenewal) {
                $this->telegram->notifyAccountRenewed($account);
            } else {
                $this->telegram->notifyAccountActivated($account);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram notification', [
                'error' => $e->getMessage(),
                'account_id' => $account->id
            ]);
            // Don't fail the webhook if notification fails
        }
    }

    /**
     * Handle failed automation callback.
     */
    protected function handleFailureCallback(IptvAccount $account, array $data, bool $isRenewal): void
    {
        Log::warning('Handling failed Ugeen automation', [
            'account_id' => $account->id,
            'error' => $data['error'],
            'is_renewal' => $isRenewal
        ]);

        // Update account with error status
        $account->update([
            'provider_status' => 'failed',
            'provider_error' => $data['error'],
            'provider_synced_at' => now(),
        ]);

        Log::info('IPTV account marked as failed', [
            'account_id' => $account->id,
            'error' => $data['error']
        ]);

        // Send Telegram notification for failures
        try {
            $this->telegram->notifyRenewalFailed($account, $data['error']);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram failure notification', [
                'error' => $e->getMessage(),
                'account_id' => $account->id
            ]);
        }
    }

    /**
     * Verify webhook authentication token.
     */
    protected function verifyWebhookToken(Request $request): void
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.ugeen_automation.webhook_token');

        if (empty($expectedToken)) {
            Log::warning('Ugeen webhook token not configured in services config');
            return; // Allow if not configured (dev mode)
        }

        if ($token !== $expectedToken) {
            Log::warning('Invalid Ugeen webhook token', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            abort(401, 'Unauthorized - Invalid webhook token');
        }
    }
}
