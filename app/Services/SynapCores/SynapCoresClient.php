<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class SynapCoresClient
{
    public function __construct(
        private readonly SynapCoresAuth $auth,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {}

    /**
     * Run a SQL query and return the result rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql): array
    {
        Log::debug('SynapCoresClient | query', ['sql' => $sql]);
        $response = $this->post('/v1/query', ['sql' => $sql]);

        return $response['rows'] ?? $response['data'] ?? $response;
    }

    /**
     * Execute a DDL statement (CREATE EXPERIMENT, TRAIN, UPDATE …).
     * Returns true on success, throws on failure.
     */
    public function execute(string $sql): bool
    {
        Log::debug('SynapCoresClient | execute', ['sql' => $sql]);
        $this->post('/v1/query', ['sql' => $sql]);

        return true;
    }

    private function post(string $path, array $payload): array
    {
        $response = $this->send($path, $payload);

        // On 401 the token may have expired — refresh once and retry
        if ($response->status() === Response::HTTP_UNAUTHORIZED) {
            Log::warning('SynapCoresClient | 401 received, retrying after token refresh');
            $this->auth->refreshToken();
            $response = $this->send($path, $payload);
        }

        if ($response->failed()) {
            $body = $response->json('message') ?? $response->json('error') ?? $response->body();
            Log::error('SynapCoresClient | request failed', [
                'path'   => $path,
                'status' => $response->status(),
                'body'   => $body,
            ]);
            throw new SynapCoresException(
                "SynapCores error {$response->status()}",
                $response->status(),
            );
        }

        return $response->json() ?? [];
    }

    private function send(string $path, array $payload): \Illuminate\Http\Client\Response
    {
        try {
            return Http::timeout($this->timeout)
                ->withToken($this->auth->getToken())
                ->acceptJson()
                ->post("{$this->baseUrl}{$path}", $payload);
        } catch (ConnectionException $e) {
            Log::error('SynapCoresClient | connection failed', [
                'base URL' => $this->baseUrl,
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            throw new SynapCoresException(
                "Cannot connect to SynapCores",
            );
        }
    }
}
