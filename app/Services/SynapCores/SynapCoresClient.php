<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SynapCoresClient
{
    public function __construct(
        private readonly SynapCoresAuth $auth,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {
    }

    /**
     * Run a SELECT against SynapCores and return rows as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $parameters = []): array
    {
        Log::debug('SynapCoresClient | query', ['sql' => $sql]);

        $payload = ['sql' => $sql];
        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        $response = $this->post('/v1/query/execute', $payload);

        $data = $response['data'] ?? [];
        $columns = array_column($data['columns'] ?? [], 'name');
        $rawRows = $data['rows'] ?? $response['rows'] ?? [];

        if (empty($columns) || empty($rawRows)) {
            return $rawRows;
        }

        return array_map(
            fn(array $row) => array_combine($columns, $row),
            $rawRows,
        );
    }

    /**
     * Execute a DDL/DML statement (CREATE TABLE, INSERT, DROP, etc.) and return the raw CE response array.
     *
     * @return array<string, mixed>
     */
    public function execute(string $sql, array $parameters = [], ?int $timeout = null): array
    {
        Log::debug('SynapCoresClient | execute', ['sql' => $sql]);

        $payload = ['sql' => $sql];
        if ($parameters !== []) {
            $payload['parameters'] = $parameters;
        }

        return $this->post('/v1/query/execute', $payload, $timeout);
    }

    /**
     * Execute a SynapCores AutoML SQL statement (CREATE EXPERIMENT, PREDICT … USING).
     * SynapCores CE returns HTTP 200 even on failure, encoding the outcome in the first row as JSON:
     *   {"status":"ok", "best_model_id": "…"}  — success
     *   {"status":"error", "error": "…"}        — failure
     * This method unwraps that envelope and throws SynapCoresException on AutoML errors,
     * so callers can use a plain try/catch without inspecting the response body.
     *
     * @return array<string, mixed>
     */
    public function executeAutoML(string $sql, ?int $timeout = null): array
    {
        Log::debug('SynapCoresClient | executeAutoML', ['sql' => $sql]);

        $response = $this->post('/v1/query/execute', ['sql' => $sql], $timeout);

        $raw = $response['data']['rows'][0][0] ?? null;

        if (is_string($raw)) {
            $result = json_decode($raw, true);
            if (isset($result['status']) && $result['status'] === 'error') {
                $msg = $result['error'] ?? 'AutoML error (no message)';
                Log::error('SynapCoresClient | AutoML error', ['error' => $msg]);
                throw new SynapCoresException($msg);
            }
        }

        return $response;
    }

    /**
     * Execute multiple SQL statements in a single HTTP round-trip via /v1/query/execute/batch.
     * Runs with stop_on_error=false so all statements are attempted regardless of individual
     * failures — callers should check each result's 'rows_affected' to detect partial failures.
     *
     * @param string[] $statements
     * @return array<int, array<string, mixed>>
     */
    public function batch(array $statements): array
    {
        Log::debug('SynapCoresClient | batch', ['count' => count($statements)]);
        $queries  = array_map(fn($sql) => ['sql' => $sql], $statements);
        $response = $this->post('/v1/query/execute/batch', [
            'queries'       => $queries,
            'stop_on_error' => false,
        ]);

        return $response['results'] ?? $response['data']['results'] ?? $response['data'] ?? [];
    }

    /**
     * Send a POST request to SynapCores CE, handle 401 token refresh with one retry,
     * and throw SynapCoresException on any non-2xx response.
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload, ?int $timeout = null): array
    {
        $response = $this->send($path, $payload, $timeout);

        if ($response->status() === 401) {
            $refreshed = $this->auth->refreshToken();
            Log::warning('SynapCoresClient | 401 received', ['token_refreshed' => $refreshed]);
            if ($refreshed) {
                $response = $this->send($path, $payload, $timeout);
            }
        }

        if ($response->failed()) {
            $humanMsg = $response->json('error.message') ?? $response->json('message') ?? $response->body();

            Log::error('SynapCoresClient | Request failed', [
                'path' => $path,
                'status' => $response->status(),
                'error' => $humanMsg,
            ]);

            throw new SynapCoresException($humanMsg, $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Perform the raw HTTP POST to SynapCores CE with the current auth token.
     * Throws SynapCoresException if the host is unreachable (ConnectionException).
     */
    private function send(string $path, array $payload, ?int $timeout = null): Response
    {
        $token = $this->auth->getToken();

        try {
            return Http::timeout($timeout ?? $this->timeout)
                ->withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}{$path}", $payload);
        } catch (ConnectionException $e) {
            Log::error('SynapCoresClient | Connection failed', [
                'base URL' => $this->baseUrl,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new SynapCoresException("Cannot connect to SynapCores");
        }
    }
}
