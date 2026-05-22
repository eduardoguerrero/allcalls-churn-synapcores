<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LoyaltyMember;
use App\Repositories\LoyaltyMemberRepositoryInterface;
use App\Services\SynapCores\Exceptions\SynapCoresException;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SynapCoresTrain extends Command
{
    protected $signature   = 'synapcores:train {--debug : Show raw SynapCores API responses}';
    protected $description = 'Score churn probability via SynapCores AutoML SQL';

    private const AUTOML_TIMEOUT = 600;

    public function __construct(
        private readonly SynapCoresClient $synapcores,
        private readonly LoyaltyMemberRepositoryInterface $members,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Log::info('synapcores:train started...');

        $total = DB::table('loyalty_members')->count();
        $this->info("Scoring {$total} members...");
        if ($total === 0) {
            $this->error('No members found. Run php artisan synapcores:seed first.');
            return self::FAILURE;
        }

        $this->info('Attempting SynapCores AutoML workflow...');
        $scores = $this->tryAutoMLPath($total);

        if ($scores === null || empty($scores)) {
            $this->error('AutoML scoring failed. Check SynapCores connectivity and re-run.');
            Log::error('synapcores:train | scoring failed');
            return self::FAILURE;
        }

        $this->saveScores($scores);

        return self::SUCCESS;
    }

    /**
     * @return array<int, float>|null
     */
    private function tryAutoMLPath(int $total): ?array
    {
        // Sync training data into SynapCores database
        $this->info('[1/3] SYNCING LOYALTY_MEMBERS TO SYNAPCORES DATABASE...');
        if (!$this->syncToSynapCores($total)) {
            return null;
        }

        // Create experiment and returns best_model_id in the response.
        $this->info('[2/3] CREATE EXPERIMENT churn_v1...');
        $modelId = null;
        try {
            // Drop any previous run so re-runs don't collide
            try {
                $this->info('Processing DROP EXPERIMENT on SynapCores...');
                $responseDropExperiment = $this->synapcores->execute('DROP EXPERIMENT IF EXISTS churn_v1');
                if ($this->option('debug')) {
                    $this->info('SynapCores DROP EXPERIMENT response:');
                    $this->line(json_encode($responseDropExperiment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
            } catch (\Throwable) {}

            $this->info('Processing CREATE EXPERIMENT on SynapCores...');
            // Synapcores CE times out loading 8000 rows inline. Train on the first 4000 (half the dataset,
            // covers all churn-signal zones); PREDICT still scores all 8000 rows in the CE table.
            $response = $this->synapcores->executeAutoML(<<<SQL
                CREATE EXPERIMENT churn_v1 AS
                SELECT
                    tier,
                    tenure_months,
                    visits_30d,
                    spend_30d,
                    churned AS target
                FROM loyalty_members
                WHERE id <= 4000
                WITH (
                    task_type           = 'binary_classification',
                    target_column       = 'target',
                    optimization_metric = 'auc',
                    max_trials          = 5,
                    time_budget_seconds = 60
                )
            SQL, self::AUTOML_TIMEOUT);

            $raw = $response['data']['rows'][0][0] ?? null;
            if (is_string($raw)) {
                $result  = json_decode($raw, true);
                $modelId = $result['best_model_id'] ?? null;
            }

            if ($this->option('debug')) {
                $this->info('SynapCores CREATE EXPERIMENT response:');
                $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $this->line("Experiment created (model: {$modelId}).");
        } catch (\Throwable $e) {
            $this->warn("  Failed: {$e->getMessage()}");
            Log::warning('synapcores:train | CREATE EXPERIMENT failed', ['error' => $e->getMessage()]);
            return null;
        }

        // PREDICT via SQL (PREDICT … USING <experiment>)
        $this->info('  [3/3] PREDICT churn_probability USING churn_v1...');

        return $this->predictViaSQL();
    }

    /**
     * Run PREDICT in batches of 1000 IDs — CE caps result rows per response.
     * Each batch queries a WHERE id range so all 8000 members get scored.
     *
     * @return array<int, float>|null
     */
    private function predictViaSQL(): ?array
    {
        $scores    = [];
        $batchSize = 1000;
        $offset    = 0;

        while (true) {
            $min = $offset + 1;
            $max = $offset + $batchSize;

            try {
                $rows = $this->synapcores->query(
                    "PREDICT churn_probability USING churn_v1 AS SELECT id, tier, tenure_months, visits_30d, spend_30d FROM loyalty_members WHERE id >= {$min} AND id <= {$max}"
                );
            } catch (\Throwable $e) {
                $this->warn("PREDICT failed (batch {$min}-{$max}): {$e->getMessage()}");
                Log::warning('synapcores:train | PREDICT failed', ['batch' => "{$min}-{$max}", 'error' => $e->getMessage()]);
                return null;
            }

            foreach ($rows as $row) {
                $id    = (int)   ($row['id'] ?? 0);
                $score = (float) ($row['churn_probability'] ?? 0.5);
                if ($id > 0) {
                    $scores[$id] = $score;
                }
            }

            if (count($rows) < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }

        if (empty($scores)) {
            $this->warn('No predictions returned.');
            return null;
        }

        $this->line('Total predictions retrieved from SynapCores: ' . count($scores));

        return $scores;
    }

    /**
     * Create loyalty_members table in SynapCores and insert all rows from local SQLite.
     *
     * @param int $total
     * @return bool
     */
    private function syncToSynapCores(int $total): bool
    {
        try {
            $this->info('Processing DROP TABLE on SynapCores...');
            $responseDrop = $this->synapcores->execute('DROP TABLE IF EXISTS loyalty_members');
            Log::info('SynapCores DROP TABLE response', ['response' => $responseDrop]);
            if ($this->option('debug')) {
                $this->info('SynapCores DROP TABLE response:');
                $this->line(json_encode($responseDrop, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $this->info('Processing CREATE TABLE on SynapCores...');
            $responseCreate = $this->synapcores->execute(
                'CREATE TABLE loyalty_members (id INTEGER PRIMARY KEY, tier TEXT, tenure_months INTEGER, visits_30d INTEGER, spend_30d REAL, churned BOOLEAN)'
            );
            Log::info('SynapCores CREATE TABLE response', ['response' => $responseCreate]);
            if ($this->option('debug')) {
                $this->info('SynapCores CREATE TABLE response:');
                $this->line(json_encode($responseCreate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

        } catch (SynapCoresException $e) {
            $this->warn("Create table in SynapCores error:: {$e->getMessage()}");
            Log::warning('Create table in SynapCores', ['error' => $e->getMessage()]);
        }

        $bar = $this->output->createProgressBar($total);
        $inserted  = 0;
        $failed    = 0;
        $authError = null;

        $bar->start();

        LoyaltyMember::select(['id', 'tier', 'tenure_months', 'visits_30d', 'spend_30d', 'churned'])
            ->orderBy('id')
            ->chunk(100, function ($members) use ($bar, &$inserted, &$failed, &$authError) {
                $statements = $members->map(function ($m) {
                    $tier     = str_replace("'", "''", $m->tier->value);
                    $spend    = number_format((float) $m->spend_30d, 2, '.', '');
                    $churned  = $m->churned ? 1 : 0;
                    return "INSERT INTO loyalty_members (id, tier, tenure_months, visits_30d, spend_30d, churned)"
                        . " VALUES ({$m->id}, '{$tier}', {$m->tenure_months}, {$m->visits_30d}, {$spend}, {$churned})";
                })->all();

                try {
                    $results = $this->synapcores->batch($statements);
                } catch (SynapCoresException $e) {
                    $authError = $e->getMessage();
                    return false; // stops chunking
                }

                foreach ($results as $i => $result) {
                    if (($result['rows_affected'] ?? 0) === 1) {
                        $inserted++;
                    } else {
                        try {
                            $this->synapcores->execute($statements[$i]);
                            $inserted++;
                        } catch (\Throwable) {
                            $failed++;
                        }
                    }
                }

                $bar->advance(count($members));
            });

        $bar->finish();
        $this->newLine();

        if ($authError !== null) {
            $this->error("SynapCores sync failed: {$authError}");
            $this->error('Check SYNAPCORES_USERNAME and SYNAPCORES_PASSWORD in .env');
            Log::error('synapcores:train | sync aborted', ['error' => $authError]);
            return false;
        }

        $this->line("Sync: {$inserted} rows written, {$failed} failed.");
        Log::info('synapcores:train | sync done', compact('inserted', 'failed'));

        if ($inserted === 0) {
            $this->warn('No rows reached SynapCores.');
            return false;
        }

        try {
            $rows = $this->synapcores->query('SELECT COUNT(id) AS total FROM loyalty_members');
            $inCloud = (int) ($rows[0]['total'] ?? 0);
            $this->info("SynapCores confirms {$inCloud} rows in loyalty_members.");
        } catch (\Throwable $e) {
            $this->warn("Could not verify row count in SynapCores: {$e->getMessage()}");
        }

        return true;
    }

    private function saveScores(array $scores): void
    {
        $this->info('Normalising and saving scores to local database...');

        $scored = $this->members->saveChurnScores($scores);
        $total  = DB::table('loyalty_members')->count();

        $this->info("Done. {$scored}/{$total} members scored");
        Log::info('synapcores:train finished', ['scored' => $scored, 'total' => $total]);
    }
}
