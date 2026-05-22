<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SynapCoresAuth
{
    private ?string $jwt = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $username,
        private readonly ?string $password,
    ) {
    }

    public function getToken(): string
    {
        if ($this->jwt === null) {
            $this->jwt = $this->login();
        }

        return $this->jwt;
    }

    public function refreshToken(): bool
    {
        $this->jwt = $this->login();

        return true;
    }

    private function login(): string
    {
        if (!$this->username || !$this->password) {
            throw new SynapCoresException('SYNAPCORES_USERNAME and SYNAPCORES_PASSWORD must be set in .env');
        }

        Log::info('SynapCoresAuth JWT| logging in via POST /v1/auth/login');
        $response = Http::post("{$this->baseUrl}/v1/auth/login", [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        if ($response->failed()) {
            $reason = $response->json('error.message') ?? $response->json('message') ?? $response->body();
            throw new SynapCoresException(
                "SynapCores authentication failed: {$reason}",
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
