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
     *     "status": "success|failed|in_progress",
     *     "username": "...",       // Optional - only for new account creation
     *     "password": "...",       // Optional - only for new account creation
     *     "host": "http://ugeen.live",  // Optional
     *     "m3u_url": "...",        // Optional
     *     "error": "...",          // Required if status=failed
     *     "message": "...",        // Progress message if status=in_progress
     *     "progress": 50,          // Progress percentage if status=in_progress
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
            'status' => 'required|in:success,failed,in_progress',
            'username' => 'nullable|string|max:255',  // Optional - Ugeen renewals don't change credentials
            'password' => 'nullable|string|max:255',  // Optional
            'host' => 'nullable|url',
            'm3u_url' => 'nullable|url',
            'error' => 'required_if:status,failed|string',
            'message' => 'required_if:status,in_progress|string',  // Progress message
            'progress' => 'nullable|integer|min:0|max:100',  // Progress percentage
            'renew_remaining_minutes' => 'nullable|integer|min:0',  // Minutes until server ready
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

            if ($data['status'] === 'in_progress') {
                $this->handleProgressCallback($account, $data);

                return response()->json([
                    'success' => true,
                    'message' => 'Progress update received',
                    'account_id' => $account->id
                ], 200);
            } elseif ($data['status'] === 'success') {
                $this->handleSuccessCallback($account, $data, $isRenewal);

                return response()->json([
                    'success' => true,
                    'message' => 'Account renewed successfully',
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
     * Handle progress update callback.
     */
    protected function handleProgressCallback(IptvAccount $account, array $data): void
    {
        $progressMessage = $data['message'] ?? 'Processing...';
        $progressPercent = $data['progress'] ?? 0;
        $renewRemainingMinutes = $data['renew_remaining_minutes'] ?? null;

        Log::info('Ugeen automation progress update', [
            'account_id' => $account->id,
            'message' => $progressMessage,
            'progress' => $progressPercent,
            'renew_remaining_minutes' => $renewRemainingMinutes
        ]);

        // Update account with progress message
        $account->update([
            'provider_status' => $progressMessage,  // Store progress message in provider_status
            'provider_synced_at' => now(),
        ]);

        // If renew_remaining_minutes is provided, schedule delayed retry
        if ($renewRemainingMinutes !== null && $renewRemainingMinutes > 0) {
            $this->scheduleDelayedRetry($account, $renewRemainingMinutes);
        }
    }

    /**
     * Handle successful automation callback.
     */
    protected function handleSuccessCallback(IptvAccount $account, array $data, bool $isRenewal): void
    {
        Log::info('Handling successful Ugeen automation', [
            'account_id' => $account->id,
            'username' => $data['username'] ?? 'N/A',
            'is_renewal' => $isRenewal
        ]);

        // Always use hardcoded Ugeen Xtream host
        $ugeenHost = 'http://ugeen.live:8080';

        // If credentials provided, create/update M3U source
        if (!empty($data['username']) && !empty($data['password'])) {
            if ($account->m3u_source_id) {
                // Update existing M3U source credentials
                $m3uSource = M3uSource::find($account->m3u_source_id);
                if ($m3uSource) {
                    $m3uSource->update([
                        'xtream_username' => $data['username'],
                        'xtream_password' => $data['password'],
                        'xtream_host' => $ugeenHost, // Always use hardcoded host
                        'status' => 'active',
                    ]);

                    Log::info('M3U source credentials updated', [
                        'source_id' => $m3uSource->id,
                        'account_id' => $account->id,
                        'host' => $ugeenHost
                    ]);
                }
            } else {
                // Create a fresh M3U source with extracted credentials
                $m3uSource = M3uSource::create([
                    'name' => "Ugeen - {$account->username}",
                    'source_type' => 'xtream',
                    'xtream_host' => $ugeenHost, // Always use hardcoded host
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
                    'account_id' => $account->id,
                    'host' => $ugeenHost,
                    'username' => $data['username']
                ]);

                // Update IPTV account with source
                $account->update([
                    'm3u_source_id' => $m3uSource->id,
                ]);
            }

            // Dispatch job to import channels from Xtream API
            if ($account->m3u_source_id) {
                ImportXtreamJob::dispatch($account->m3u_source_id);

                Log::info('ImportXtreamJob dispatched', [
                    'source_id' => $account->m3u_source_id,
                    'account_id' => $account->id
                ]);
            }
        } else {
            Log::warning('No credentials provided in webhook - cannot create/update M3U source', [
                'account_id' => $account->id
            ]);
        }

        // Update IPTV account provider status and clear retry scheduling
        $account->update([
            'provider_status' => 'done',
            'provider_error' => null,
            'provider_synced_at' => now(),
            'retry_scheduled_at' => null,  // Clear retry scheduling on success
        ]);

        Log::info('IPTV account updated', [
            'account_id' => $account->id,
            'source_id' => $account->m3u_source_id
        ]);

        // Send Telegram notification
        try {
            $this->telegram->notifyAccountRenewed($account);
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

    /**
     * Schedule a delayed retry for account renewal.
     */
    protected function scheduleDelayedRetry(IptvAccount $account, int $remainingMinutes): void
    {
        // Add buffer (default 2 minutes) to ensure server is ready
        $bufferMinutes = config('services.ugeen_automation.retry_buffer_minutes', 2);
        $delayMinutes = $remainingMinutes + $bufferMinutes;

        // Calculate retry time
        $retryAt = now()->addMinutes($delayMinutes);

        Log::info('Scheduling delayed Ugeen renewal retry', [
            'account_id' => $account->id,
            'remaining_minutes' => $remainingMinutes,
            'buffer_minutes' => $bufferMinutes,
            'total_delay_minutes' => $delayMinutes,
            'retry_at' => $retryAt->toDateTimeString(),
        ]);

        // Dispatch delayed job
        \App\Jobs\GenerateProviderAccountJob::dispatch($account->id, isRenewal: true)
            ->delay($retryAt)
            ->onQueue('default');

        // Update account with retry schedule
        $account->update([
            'retry_scheduled_at' => $retryAt,
        ]);
    }
}
