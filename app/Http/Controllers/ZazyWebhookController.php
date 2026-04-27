<?php

namespace App\Http\Controllers;

use App\Models\IptvAccount;
use App\Models\M3uSource;
use App\Jobs\ImportXtreamJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ZazyWebhookController extends Controller
{
    /**
     * Handle webhook callback from Zazy automation script.
     *
     * Expected payload:
     * {
     *     "user_id": 123,
     *     "status": "success|failed",
     *     "username": "...",
     *     "password": "...",
     *     "host": "http://live.zazytv.com",
     *     "m3u_url": "...",
     *     "error": "...",
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
            'timestamp' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            Log::warning('Zazy webhook validation failed', [
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

        try {
            // Find the IPTV account
            $account = IptvAccount::findOrFail($data['user_id']);

            Log::info('Processing Zazy webhook', [
                'account_id' => $account->id,
                'username' => $account->username,
                'status' => $data['status']
            ]);

            if ($data['status'] === 'success') {
                $this->handleSuccessCallback($account, $data);

                return response()->json([
                    'success' => true,
                    'message' => 'Credentials updated successfully',
                    'account_id' => $account->id
                ], 200);
            } else {
                $this->handleFailureCallback($account, $data);

                return response()->json([
                    'success' => false,
                    'message' => 'Automation failed',
                    'account_id' => $account->id,
                    'error' => $data['error']
                ], 200); // Still return 200 to acknowledge receipt
            }
        } catch (\Exception $e) {
            Log::error('Error processing Zazy webhook', [
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
    protected function handleSuccessCallback(IptvAccount $account, array $data): void
    {
        Log::info('Handling successful Zazy automation', [
            'account_id' => $account->id,
            'username' => $data['username']
        ]);

        // Create or update M3U source with Xtream credentials
        $m3uSource = M3uSource::updateOrCreate(
            ['id' => $account->m3u_source_id],
            [
                'name' => "Zazy - {$account->username}",
                'source_type' => 'xtream',
                'xtream_host' => $data['host'],
                'xtream_username' => $data['username'],
                'xtream_password' => $data['password'],
                'xtream_stream_types' => ['live', 'movie', 'series'],
                'status' => 'active',
                'is_active' => true,
                'excluded_groups' => ['24/7'], // Exclude VOD groups
            ]
        );

        Log::info('M3U source created/updated', [
            'source_id' => $m3uSource->id,
            'account_id' => $account->id
        ]);

        // Update IPTV account with source and provider status
        $account->update([
            'm3u_source_id' => $m3uSource->id,
            'provider_status' => 'done',
            'provider_error' => null,
            'provider_synced_at' => now(),
        ]);

        Log::info('IPTV account updated with M3U source', [
            'account_id' => $account->id,
            'source_id' => $m3uSource->id
        ]);

        // Dispatch job to import channels from Xtream API
        ImportXtreamJob::dispatch($m3uSource);

        Log::info('ImportXtreamJob dispatched', [
            'source_id' => $m3uSource->id,
            'account_id' => $account->id
        ]);
    }

    /**
     * Handle failed automation callback.
     */
    protected function handleFailureCallback(IptvAccount $account, array $data): void
    {
        Log::warning('Handling failed Zazy automation', [
            'account_id' => $account->id,
            'error' => $data['error']
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
    }

    /**
     * Verify webhook authentication token.
     */
    protected function verifyWebhookToken(Request $request): void
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.zazy_automation.webhook_token');

        if (empty($expectedToken)) {
            Log::warning('Zazy webhook token not configured in services config');
            return; // Allow if not configured (dev mode)
        }

        if ($token !== $expectedToken) {
            Log::warning('Invalid Zazy webhook token', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent()
            ]);

            abort(401, 'Unauthorized - Invalid webhook token');
        }
    }
}
