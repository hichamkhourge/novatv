<?php

namespace App\Http\Controllers;

use App\Jobs\ImportXtreamJob;
use App\Models\IptvAccount;
use App\Models\M3uSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class LayerSevenWebhookController extends Controller
{
    /**
     * Handle webhook callback from LayerSeven automation script.
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $this->verifyWebhookToken($request);

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
            Log::warning('LayerSeven webhook validation failed', [
                'errors' => $validator->errors()->toArray(),
                'payload' => $request->except(['password']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $account = IptvAccount::findOrFail($data['user_id']);

            Log::info('Processing LayerSeven webhook', [
                'account_id' => $account->id,
                'username' => $account->username,
                'status' => $data['status'],
            ]);

            if ($data['status'] === 'success') {
                $this->handleSuccessCallback($account, $data);

                return response()->json([
                    'success' => true,
                    'message' => 'Credentials updated successfully',
                    'account_id' => $account->id,
                ], 200);
            }

            $this->handleFailureCallback($account, $data);

            return response()->json([
                'success' => false,
                'message' => 'Automation failed',
                'account_id' => $account->id,
                'error' => $data['error'],
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error processing LayerSeven webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->except(['password']),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Internal server error',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    protected function handleSuccessCallback(IptvAccount $account, array $data): void
    {
        Log::info('Handling successful LayerSeven automation', [
            'account_id' => $account->id,
            'username' => $data['username'],
        ]);

        $m3uSource = M3uSource::create([
            'name' => "LayerSeven - {$account->username}",
            'source_type' => 'xtream',
            'xtream_host' => $data['host'],
            'xtream_username' => $data['username'],
            'xtream_password' => $data['password'],
            'xtream_stream_types' => ['live'],
            'status' => 'active',
            'is_active' => true,
            'excluded_groups' => ['24/7'],
        ]);

        Log::info('LayerSeven M3U source created', [
            'source_id' => $m3uSource->id,
            'account_id' => $account->id,
        ]);

        $account->update([
            'm3u_source_id' => $m3uSource->id,
            'provider_status' => 'done',
            'provider_error' => null,
            'provider_synced_at' => now(),
        ]);

        ImportXtreamJob::dispatch($m3uSource->id);

        Log::info('LayerSeven ImportXtreamJob dispatched', [
            'source_id' => $m3uSource->id,
            'account_id' => $account->id,
        ]);
    }

    protected function handleFailureCallback(IptvAccount $account, array $data): void
    {
        Log::warning('Handling failed LayerSeven automation', [
            'account_id' => $account->id,
            'error' => $data['error'],
        ]);

        $account->update([
            'provider_status' => 'failed',
            'provider_error' => $data['error'],
            'provider_synced_at' => now(),
        ]);
    }

    protected function verifyWebhookToken(Request $request): void
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.layerseven_automation.webhook_token');

        if (empty($expectedToken)) {
            Log::warning('LayerSeven webhook token not configured in services config');
            return;
        }

        if ($token !== $expectedToken) {
            Log::warning('Invalid LayerSeven webhook token', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            abort(401, 'Unauthorized - Invalid webhook token');
        }
    }
}
