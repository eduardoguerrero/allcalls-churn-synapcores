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
        private readonly ?string $database,
        private readonly int $timeout,
    ) {}

    /**
     * Run a SQL query and return result rows as associative arrays.
     *
     * CE envelope: {"data":{"columns":[{"name":"x"}],"rows":[[val,...]]}}
     *
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql): array
    {
        Log::debug('SynapCoresClient | query', ['sql' => $sql]);
        $response = $this->post('/v1/query/execute', ['sql' => $sql]);

        $data    = $response['data'] ?? [];
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
     * Execute a DDL/DML statement. Returns true on success, throws on failure.
     */
    public function execute(string $sql): array
    {
        Log::debug('SynapCoresClient | execute', ['sql' => $sql]);

        $result = $this->post('/v1/query/execute', ['sql' => $sql]);

        return $result;
    }

    /**
     * Score rows against a trained AutoML model via the REST endpoint.
     *
     * @param  string                           $modelId  e.g. 'churn_v1'
     * @param  array<int, array<string, mixed>> $rows     feature rows
     * @return array<int, float|null>
     */
    public function automlPredict(string $modelId, array $rows): array
    {
        Log::debug('SynapCoresClient | automlPredict', ['model' => $modelId, 'rows' => count($rows)]);
        $response = $this->post("/v1/automl/models/{$modelId}/predict", ['rows' => $rows]);

        $predictions = $response['predictions']
            ?? $response['data']['predictions']
            ?? [];

        return array_map(fn($p) => is_numeric($p) ? (float) $p : null, $predictions);
    }

    /**
     * Generate embeddings for multiple texts in one round-trip.
     * Returns a list of float vectors (one per input text).
     *
     * @param  string[]         $texts
     * @return array<int, float[]>
     */
    public function batchEmbeddings(array $texts): array
    {
        Log::debug('SynapCoresClient | batchEmbeddings', ['count' => count($texts)]);
        $response = $this->post('/v1/ai/embeddings/batch', ['texts' => $texts]);

        return $response['data']['embeddings'] ?? $response['embeddings'] ?? [];
    }

    /**
     * Execute multiple SQL statements in one round-trip.
     *
     * @param  string[] $statements
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

        Log::debug('Response from batch endpoint', ['response' => $response]);           


        return $response['results'] ?? $response['data']['results'] ?? $response['data'] ?? [];
    }

    private function post(string $path, array $payload): array
    {
        if ($this->database !== null && !isset($payload['database'])
            && str_starts_with($path, '/v1/query')) {
            $payload['database'] = $this->database;
        }

        $response = $this->send($path, $payload);

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
                "SynapCores error {$response->body()}",
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
                'path'     => $path,
                'error'    => $e->getMessage(),
            ]);
            throw new SynapCoresException("Cannot connect to SynapCores");
        }
    }
}
