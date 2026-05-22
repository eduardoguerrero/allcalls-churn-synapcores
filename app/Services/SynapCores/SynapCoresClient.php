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

    // ─── SQL layer  (/v1/query/*)  ────────────────────────────────────────────

    /**
     * Run a SELECT and return result rows as associative arrays.
     * Supports PostgreSQL-style positional parameters ($1, $2, …).
     *
     * @param  list<mixed>              $parameters
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
     * Execute a DDL/DML statement.
     * Supports PostgreSQL-style positional parameters ($1, $2, …).
     * Pass $timeout (seconds) to override the default for long-running statements.
     *
     * @param  list<mixed> $parameters
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

        return $response['results'] ?? $response['data']['results'] ?? $response['data'] ?? [];
    }

    // ─── AutoML REST API  (/v1/automl/*)  ────────────────────────────────────

    /**
     * Register a dataset for AutoML training.
     * Returns the dataset ID to pass to trainAutomlModel().
     *
     * POST /v1/automl/datasets
     *
     * @param  array<int, array<string, mixed>> $rows
     */
    public function createAutomlDataset(string $name, array $rows, string $targetColumn): string
    {
        Log::debug('SynapCoresClient | createAutomlDataset', ['name' => $name, 'rows' => count($rows)]);
        $response = $this->post('/v1/automl/datasets', [
            'name'          => $name,
            'dataset_type'  => 'classification',
            'source'        => $rows,
            'target_column' => $targetColumn,
        ]);

        $id = $response['data']['id'] ?? $response['id'] ?? null;

        if ($id === null) {
            throw new SynapCoresException('createAutomlDataset: no id in response');
        }

        return (string) $id;
    }

    /**
     * Start an AutoML training job.
     * Returns the model ID to pass to automlPredict().
     *
     * POST /v1/automl/train
     */
    public function trainAutomlModel(string $datasetId, ?int $timeout = null): string
    {
        Log::debug('SynapCoresClient | trainAutomlModel', ['dataset_id' => $datasetId]);
        $response = $this->post('/v1/automl/train', [
            'dataset_id'          => $datasetId,
            'task'                => 'classification',
            'target_column'       => 'churned',
            'time_budget_seconds' => $timeout ?? 120,
        ], $timeout);

        $id = $response['data']['id']
            ?? $response['data']['model_id']
            ?? $response['id']
            ?? $response['model_id']
            ?? null;

        if ($id === null) {
            throw new SynapCoresException('trainAutomlModel: no model id in response');
        }

        return (string) $id;
    }

    /**
     * Score rows against a trained AutoML model.
     *
     * POST /v1/automl/models/{id}/predict
     *
     * @param  array<int, array<string, mixed>> $features
     * @return array<int, float|null>
     */
    public function automlPredict(string $modelId, array $features): array
    {
        Log::debug('SynapCoresClient | automlPredict', ['model' => $modelId, 'rows' => count($features)]);
        $response = $this->post("/v1/automl/models/{$modelId}/predict", ['features' => $features]);

        $predictions = $response['data']['predictions']
            ?? $response['predictions']
            ?? [];

        return array_map(fn($p) => is_numeric($p) ? (float) $p : null, $predictions);
    }

    // ─── AI Embeddings  ───────────────────────────────────────────────────────

    /**
     * Generate embeddings for multiple texts in one round-trip.
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

    // ─── HTTP internals  ──────────────────────────────────────────────────────

    private function post(string $path, array $payload, ?int $timeout = null): array
    {
        // CE uses the default database for all user tables regardless of the
        // tenant parameter — injecting it routes queries to an empty namespace.
        // Database routing is intentionally omitted here.

        $response = $this->send($path, $payload, $timeout);

        if ($response->status() === Response::HTTP_UNAUTHORIZED) {
            Log::warning('SynapCoresClient | 401 received, retrying after token refresh');
            $this->auth->refreshToken();
            $response = $this->send($path, $payload, $timeout);
        }

        if ($response->failed()) {
            $humanMsg = $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body();

            Log::error('SynapCoresClient | request failed', [
                'path'   => $path,
                'status' => $response->status(),
                'error'  => $humanMsg,
            ]);
            throw new SynapCoresException($humanMsg, $response->status());
        }

        return $response->json() ?? [];
    }

    private function send(string $path, array $payload, ?int $timeout = null): \Illuminate\Http\Client\Response
    {
        //$token = $this->auth->getToken();

        $token = $this->auth->login();

        //print_r($token); // DEBUG

        //exit; // DEBUG

        try {
            return Http::timeout($timeout ?? $this->timeout)
                //->withHeaders(['X-API-Key' => $token])
                ->withToken($token)          // also sets Authorization: Bearer for JWT flows
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
