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
    ) {}

    public function getToken(): string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            return $this->apiKey;
        }

        if ($this->username && $this->password) {
            if ($this->jwt === null) {
                $this->jwt = $this->login();
            }
            return $this->jwt;
        }

        throw new \RuntimeException('No SynapCores credentials configured');
    }

    public function refreshToken(): string
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            Log::debug('SynapCoresAuth | refresh requested, API key is static');
            return $this->apiKey;
        }

        if ($this->username && $this->password) {
            $this->jwt = $this->login();
            return $this->jwt;
        }

        return '';
    }

    public function login(): string
    {
        /*print_r($this->baseUrl);

        print_r($this->username);
              print_r($this->password);*/



        Log::debug('SynapCoresAuth | logging in via POST /v1/auth/login');

        $response = Http::post("{$this->baseUrl}/v1/auth/login", [
            'username' => $this->username,
            'password' => $this->password,
        ]);

        /*print_r($response->status());
        print_r($response->body()); */

        if ($response->failed()) {
            throw new SynapCoresException(
                'SynapCores login failed: ' . ($response->json('message') ?? $response->body()),
                $response->status(),
            );
        }

        $token = $response->json('access_token');

        if (!is_string($token) || $token === '') {
            throw new SynapCoresException('SynapCores login returned no access_token');
        }

        Log::debug('SynapCoresAuth | JWT obtained');
        
        return $token;
    }
}
