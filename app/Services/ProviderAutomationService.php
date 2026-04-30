<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client wrapper for the Zazy Automation API.
 *
 * The automation API runs in the zazy-automation Docker container and is
 * reachable internally via http://zazy-automation:5000 on dokploy-network.
 */
class ProviderAutomationService
{
    private string $baseUrl;
    private string $apiKey;

    /** Timeout in seconds — Selenium + 2captcha can take up to ~8 minutes */
    private int $timeout = 600;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.automation_api.url', 'http://zazy-automation:5000'), '/');
        $this->apiKey  = config('services.automation_api.key', '');
    }

    /**
     * Check if the automation API is reachable.
     */
    public function isHealthy(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/api/health");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Generate a new Zazy account and return Xtream credentials (old synchronous method).
     *
     * @deprecated Use generateZazyViaScript() instead for async callback-based approach
     * @return array{
     *   success: bool,
     *   xtream_host: string|null,
     *   xtream_username: string|null,
     *   xtream_password: string|null,
     *   m3u_url: string|null,
     *   error: string|null,
     *   logs: string|null,
     * }
     */
    public function generateZazy(): array
    {
        return $this->call('POST', '/api/generate/zazy');
    }

    /**
     * Trigger Zazy account generation via Python script (async with webhook callback).
     * The script will POST results back to our webhook endpoint when complete.
     *
     * @param int $accountId The IPTV account ID
     * @return array{success: bool, message: string, error: string|null}
     */
    public function generateZazyViaScript(int $accountId): array
    {
        $callbackUrl = config('app.url') . '/api/webhooks/zazy-automation';

        Log::info('[ProviderAutomationService] Triggering Zazy script via Flask API', [
            'account_id' => $accountId,
            'callback_url' => $callbackUrl,
            'base_url' => $this->baseUrl,
            'api_key_length' => strlen($this->apiKey),
            'api_key_first_10' => substr($this->apiKey, 0, 10),
            'api_key_last_10' => substr($this->apiKey, -10),
        ]);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(30) // Short timeout - the script runs in background
                ->post("{$this->baseUrl}/api/generate", [
                    'user_id' => $accountId,
                    'callback_url' => $callbackUrl,
                ]);

            $body = $response->json();

            if (! $response->successful()) {
                Log::error('[ProviderAutomationService] Flask API error response', [
                    'status' => $response->status(),
                    'body' => $body,
                    'sent_api_key_length' => strlen($this->apiKey),
                    'sent_api_key_first_10' => substr($this->apiKey, 0, 10),
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to trigger automation script',
                    'error' => $body['error'] ?? "HTTP {$response->status()}",
                ];
            }

            Log::info('[ProviderAutomationService] Flask API call succeeded', [
                'status' => $body['status'] ?? 'unknown',
                'account_id' => $accountId,
            ]);

            return [
                'success' => true,
                'message' => $body['message'] ?? 'Automation started',
                'error' => null,
            ];

        } catch (ConnectionException $e) {
            Log::error('[ProviderAutomationService] Connection failed to Flask API', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Could not connect to automation API',
                'error' => $e->getMessage(),
            ];

        } catch (\Throwable $e) {
            Log::error('[ProviderAutomationService] Unexpected error calling Flask API', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Automation API call failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger Ugeen account generation via Python script (async with webhook callback).
     * The script will POST results back to our webhook endpoint when complete.
     *
     * @param int $accountId The IPTV account ID
     * @param string|null $username Provider username (optional, falls back to env)
     * @param string|null $password Provider password (optional, falls back to env)
     * @return array{success: bool, message: string, error: string|null}
     */
    public function generateUgeenViaScript(int $accountId, ?string $username = null, ?string $password = null): array
    {
        $callbackUrl = config('app.url') . '/api/webhooks/ugeen-automation';

        Log::info('[ProviderAutomationService] Triggering Ugeen script via Flask API', [
            'account_id' => $accountId,
            'callback_url' => $callbackUrl,
            'has_custom_credentials' => !empty($username),
        ]);

        try {
            $payload = [
                'user_id' => $accountId,
                'callback_url' => $callbackUrl,
            ];

            // Add custom credentials if provided
            if (!empty($username) && !empty($password)) {
                $payload['username'] = $username;
                $payload['password'] = $password;
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(30) // Short timeout - the script runs in background
                ->post("{$this->baseUrl}/api/generate/ugeen", $payload);

            $body = $response->json();

            if (! $response->successful()) {
                Log::error('[ProviderAutomationService] Flask API error response for Ugeen', [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to trigger Ugeen automation script',
                    'error' => $body['error'] ?? "HTTP {$response->status()}",
                ];
            }

            Log::info('[ProviderAutomationService] Ugeen Flask API call succeeded', [
                'status' => $body['status'] ?? 'unknown',
                'account_id' => $accountId,
            ]);

            return [
                'success' => true,
                'message' => $body['message'] ?? 'Ugeen automation started',
                'error' => null,
            ];

        } catch (ConnectionException $e) {
            Log::error('[ProviderAutomationService] Connection failed to Flask API for Ugeen', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Could not connect to automation API',
                'error' => $e->getMessage(),
            ];

        } catch (\Throwable $e) {
            Log::error('[ProviderAutomationService] Unexpected error calling Flask API for Ugeen', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Ugeen automation API call failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger Ugeen account renewal via Python script (async with webhook callback).
     * The script will POST results back to our webhook endpoint when complete.
     *
     * @param int $accountId The IPTV account ID
     * @param string|null $username Provider username (from m3u_source)
     * @param string|null $password Provider password (from m3u_source)
     * @param string|null $packageId Package ID for renewal (from provider_config)
     * @return array{success: bool, message: string, error: string|null}
     */
    public function renewUgeenViaScript(int $accountId, ?string $username = null, ?string $password = null, ?string $packageId = null): array
    {
        $callbackUrl = config('app.url') . '/api/webhooks/ugeen-automation';

        Log::info('[ProviderAutomationService] Triggering Ugeen renewal script via Flask API', [
            'account_id' => $accountId,
            'callback_url' => $callbackUrl,
            'has_custom_credentials' => !empty($username),
            'package_id' => $packageId,
        ]);

        try {
            $payload = [
                'user_id' => $accountId,
                'callback_url' => $callbackUrl,
                'is_renewal' => true,
            ];

            // Add custom credentials if provided
            if (!empty($username) && !empty($password)) {
                $payload['username'] = $username;
                $payload['password'] = $password;
            }

            // Add package ID if provided
            if (!empty($packageId)) {
                $payload['package_id'] = $packageId;
            }

            $response = Http::withToken($this->apiKey)
                ->timeout(30) // Short timeout - the script runs in background
                ->post("{$this->baseUrl}/api/generate/ugeen", $payload);

            $body = $response->json();

            if (! $response->successful()) {
                Log::error('[ProviderAutomationService] Flask API error response for Ugeen renewal', [
                    'status' => $response->status(),
                    'body' => $body,
                ]);

                return [
                    'success' => false,
                    'message' => 'Failed to trigger Ugeen renewal script',
                    'error' => $body['error'] ?? "HTTP {$response->status()}",
                ];
            }

            Log::info('[ProviderAutomationService] Ugeen renewal Flask API call succeeded', [
                'status' => $body['status'] ?? 'unknown',
                'account_id' => $accountId,
            ]);

            return [
                'success' => true,
                'message' => $body['message'] ?? 'Ugeen renewal started',
                'error' => null,
            ];

        } catch (ConnectionException $e) {
            Log::error('[ProviderAutomationService] Connection failed to Flask API for Ugeen renewal', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Could not connect to automation API',
                'error' => $e->getMessage(),
            ];

        } catch (\Throwable $e) {
            Log::error('[ProviderAutomationService] Unexpected error calling Flask API for Ugeen renewal', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'Ugeen renewal API call failed',
                'error' => $e->getMessage(),
            ];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function call(string $method, string $path): array
    {
        $url = $this->baseUrl . $path;

        Log::info('[ProviderAutomationService] Calling automation API', [
            'method' => $method,
            'url'    => $url,
        ]);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->send($method, $url);

            $body = $response->json();

            if (! $response->successful()) {
                Log::error('[ProviderAutomationService] API error response', [
                    'status' => $response->status(),
                    'body'   => $body,
                ]);

                return $this->errorResult(
                    "Automation API returned HTTP {$response->status()}: " . ($body['detail'] ?? 'Unknown error')
                );
            }

            Log::info('[ProviderAutomationService] API call succeeded', [
                'success'  => $body['success'] ?? false,
                'username' => $body['xtream_username'] ?? null,
            ]);

            return $body;

        } catch (ConnectionException $e) {
            Log::error('[ProviderAutomationService] Connection failed', ['error' => $e->getMessage()]);
            return $this->errorResult("Could not connect to automation API: {$e->getMessage()}");

        } catch (\Throwable $e) {
            Log::error('[ProviderAutomationService] Unexpected error', ['error' => $e->getMessage()]);
            return $this->errorResult("Automation API call failed: {$e->getMessage()}");
        }
    }

    private function errorResult(string $message): array
    {
        return [
            'success'          => false,
            'xtream_host'      => null,
            'xtream_username'  => null,
            'xtream_password'  => null,
            'm3u_url'          => null,
            'error'            => $message,
            'logs'             => null,
        ];
    }
}
