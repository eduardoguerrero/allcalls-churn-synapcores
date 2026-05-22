<?php

declare(strict_types=1);

namespace App\Services\SynapCores;

use App\Services\SynapCores\Exceptions\SynapCoresException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SynapCoresClient
{
    public function __construct(
        private readonly SynapCoresAuth $auth,
        private readonly string $baseUrl,
        private readonly int $timeout,
    ) {
    }

    /**
     * Run a SELECT and return result rows as associative arrays.
     * Supports PostgreSQL-style positional parameters ($1, $2, …).
     *
     * @param list<mixed> $parameters
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
     * Execute a DDL/DML statement.
     * Supports PostgreSQL-style positional parameters ($1, $2, …).
     * Pass $timeout (seconds) to override the default for long-running statements.
     *
     * @param list<mixed> $parameters
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
     * Execute a SynapCores AutoML SQL statement (CREATE EXPERIMENT, DEPLOY MODEL, PREDICT).
     * These return HTTP 200 even on failure, encoding the result in an `automl_result`
     * column as JSON: {"status":"ok"} or {"status":"error","error":"..."}.
     * This method parses that envelope and throws SynapCoresException on AutoML errors.
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
     * Execute multiple SQL statements in one round-trip.
     *
     * @param string[] $statements
     * @return array<int, array<string, mixed>>
     */
    public function batch(array $statements): array
    {
        Log::debug('SynapCoresClient | batch', ['count' => count($statements)]);
        $queries = array_map(fn($sql) => ['sql' => $sql], $statements);
        $response = $this->post('/v1/query/execute/batch', [
            'queries' => $queries,
            'stop_on_error' => false,
        ]);

        return $response['results'] ?? $response['data']['results'] ?? $response['data'] ?? [];
    }

    private function post(string $path, array $payload, ?int $timeout = null): array
    {
        $response = $this->send($path, $payload, $timeout);

        if ($response->status() === 401) {
            Log::warning('SynapCoresClient| 401 HTTP_UNAUTHORIZED received, retrying after token refresh');
            $this->auth->refreshToken();
            $response = $this->send($path, $payload, $timeout);
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

    private function send(string $path, array $payload, ?int $timeout = null): Response
    {
        $token = $this->auth->login();

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
