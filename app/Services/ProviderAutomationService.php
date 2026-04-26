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
     * Generate a new Zazy account and return Xtream credentials.
     *
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
