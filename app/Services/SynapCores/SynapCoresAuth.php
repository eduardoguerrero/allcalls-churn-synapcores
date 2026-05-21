<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SynapCoresAuth
{
    private const CACHE_KEY = 'synapcores_jwt';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
    ) {}

    public function getToken(): string
    {
        return Cache::remember(self::CACHE_KEY, $this->tokenTtl(), fn () => $this->fetchToken());
    }

    public function refreshToken(): string
    {
        Cache::forget(self::CACHE_KEY);

        return $this->getToken();
    }

    private function fetchToken(): string
    {
        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/v1/auth/login", [
                    'api_key' => $this->apiKey,
                ]);
        } catch (ConnectionException $e) {
            throw new SynapCoresException("Cannot connect to SynapCores at {$this->baseUrl}: {$e->getMessage()}");
        }

        if ($response->failed()) {
            throw new SynapCoresException(
                "SynapCores auth failed: {$response->body()}",
                $response->status(),
            );
        }

        $token = $response->json('token') ?? $response->json('access_token');

        if (! $token) {
            throw new SynapCoresException('SynapCores auth response did not include a token');
        }

        return $token;
    }

    /** Cache for 55 minutes — tokens typically expire after 1 hour */
    private function tokenTtl(): int
    {
        return 55 * 60;
    }
}
