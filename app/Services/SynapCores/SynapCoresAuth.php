<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use Illuminate\Support\Facades\Log;

class SynapCoresAuth
{
    public function __construct(private readonly string $apiKey)
    { }

    public function getToken(): string
    {
        Log::debug('SynapCoresAuth | using API key as bearer token');

        return $this->apiKey;
    }

    public function refreshToken(): string
    {
        // The API key is a static credential — nothing to refresh.
        Log::debug('SynapCoresAuth | refresh requested, API key is static');

        return $this->apiKey;
    }
}
