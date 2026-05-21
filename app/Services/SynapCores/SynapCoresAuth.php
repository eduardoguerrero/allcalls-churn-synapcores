<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SynapCoresAuth
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
    ) {}

    private function cacheKey(): string
    {
        return 'synapcores_jwt_' . md5($this->apiKey);
    }

    public function getToken(): string
    {
        return Cache::remember($this->cacheKey(), $this->tokenTtl(), function () {
            Log::debug('SynapCoresAuth | cache miss, fetching new JWT');

            return $this->fetchToken();
        });
    }

    public function refreshToken(): string
    {
        Log::info('SynapCoresAuth | refreshing JWT (token expired or 401)');
        Cache::forget($this->cacheKey());

        return $this->getToken();
    }

    private function fetchToken(): string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/v1/auth/login", ['api_key' => $this->apiKey]);
        } catch (ConnectionException $e) {
            Log::error('SynapCoresAuth | connection failed', ['error' => $e->getMessage()]);
            throw new SynapCoresException("Cannot connect to SynapCores");
        }

        if ($response->failed()) {
            Log::error('SynapCoresAuth | login failed', ['status' => $response->status()]);
            throw new SynapCoresException(
                "SynapCores auth failed: {$response->body()}",
                $response->status(),
            );
        }

        $token = $response->json('token') ?? $response->json('access_token');

        if (!$token) {
            Log::error('SynapCoresAuth | response missing token field');
            throw new SynapCoresException('SynapCores auth response did not include a token');
        }

        Log::debug('SynapCoresAuth | JWT obtained successfully');

        return $token;
    }

    /** Cache for 55 minutes — tokens typically expire after 1 hour */
    private function tokenTtl(): int
    {
        return 55 * 60;
    }
}
