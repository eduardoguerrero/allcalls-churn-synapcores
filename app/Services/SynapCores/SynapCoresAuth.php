<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SynapCoresAuth
{
    private ?string $jwt = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly ?string $apiKey,
    ) {
    }

    public function getToken(): string
    {
        if ($this->username && $this->password) {
            if ($this->jwt === null) {
                $this->jwt = $this->login();
            }
            return $this->jwt;
        }

        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        throw new \RuntimeException('No SynapCores credentials configured');
    }

    public function refreshToken(): bool
    {
        if ($this->username && $this->password) {
            $this->jwt = $this->login();
            return true;
        }

        return false;
    }

    public function login(): string
    {
        Log::info('SynapCoresAuth JWT| logging in via POST /v1/auth/login');
        $response = Http::post("{$this->baseUrl}/v1/auth/login", [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if ($response->failed()) {
            throw new SynapCoresException(
                'SynapCores login failed: ' . ($response->json('message') ?? $response->body()),
                $response->status(),
            );
        }
        Log::info('SynapCoresAuth JWT| logging success');

        $token = $response->json('access_token');

        if (!is_string($token) || $token === '') {
            Log::error('SynapCoresAuth | empty or invalid JWT access_token', [
                'token_type' => gettype($token),
                'token_empty' => $token === '',
            ]);

            throw new SynapCoresException('SynapCores login returned no JWT access_token');
        }

        Log::info('SynapCoresAuth | JWT obtained');

        return $token;
    }
}
