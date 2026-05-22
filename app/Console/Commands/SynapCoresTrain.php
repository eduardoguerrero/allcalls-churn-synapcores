<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LoyaltyMember;
use App\Services\SynapCores\Exceptions\SynapCoresException;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SynapCoresTrain extends Command
{
    protected $signature   = 'synapcores:train';
    protected $description = 'Score churn probability via SynapCores AutoML SQL (falls back to AI Embeddings)';

    // Budget for CREATE EXPERIMENT + DEPLOY (CE may take several minutes)
    private const AUTOML_TIMEOUT = 300;

    public function __construct(
        private readonly SynapCoresClient $synapcores,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Log::info('synapcores:train started');

        $total = DB::table('loyalty_members')->count();
        $this->info("Scoring {$total} members…");

        if ($total === 0) {
            $this->error('No members found. Run php artisan synapcores:seed first.');
            return self::FAILURE;
        }

        // Path 1 — SynapCores AutoML SQL (recipe workflow):
        //   CREATE TABLE loyalty_members → INSERT data
        //   → CREATE EXPERIMENT … WITH (task_type = 'binary_classification', …)
        //   → DEPLOY MODEL churn_predictor FROM EXPERIMENT churn_v1
        //   → PREDICT churn_probability USING churn_predictor AS SELECT … FROM loyalty_members
        $this->info('[AutoML] Attempting SynapCores AutoML SQL workflow…');
        $scores = $this->tryAutoMLPath($total);

        // Path 2 — Cosine similarity over SynapCores AI Embeddings (no table required)
        if ($scores === null) {
            $this->warn('[Embeddings] AutoML unavailable — falling back to AI Embeddings…');
            Log::warning('synapcores:train | AutoML path failed, switching to embeddings fallback');
            $scores = $this->tryEmbeddingsPath($total);
        }

        if ($scores === null || empty($scores)) {
            $this->error('Both scoring paths failed. Check SynapCores connectivity.');
            Log::error('synapcores:train | all paths failed');
            return self::FAILURE;
        }

        $this->saveScores($scores);
        return self::SUCCESS;
    }

    // ─── AutoML SQL path  (recipe: synapcores.com/developers) ────────────────

    /**
     * @return array<int, float>|null
     */
    private function tryAutoMLPath(int $total): ?array
    {
        // ── Step 1: sync training data into SynapCores ───────────────────────
        $this->info('  [1/4] Syncing loyalty_members to SynapCores…');

        if (!$this->syncToSynapCores($total)) {
            return null;
        }

        // ── Step 2: CREATE EXPERIMENT ────────────────────────────────────────
        // Recipe syntax: CREATE EXPERIMENT name AS SELECT … WITH (task_type = …)
        // Target column is aliased to 'target' inside the SELECT.
        $this->info('  [2/4] CREATE EXPERIMENT churn_v1…');
        try {
            $responseExperiment = $this->synapcores->executeAutoML(<<<SQL
                CREATE EXPERIMENT IF NOT EXISTS churn_v1 AS
                SELECT
                    tier,
                    tenure_months,
                    visits_30d,
                    spend_30d,
                    churned AS target
                FROM loyalty_members
                WITH (
                    task_type            = 'binary_classification',
                    target_column        = 'target',
                    optimization_metric  = 'auc',
                    max_trials           = 10,
                    time_budget_seconds  = 120
                )
            SQL, self::AUTOML_TIMEOUT);

            print_r($responseExperiment); // DEBUG
            $this->line('Experiment created.');

        } catch (\Throwable $e) {
            $this->warn("        Failed: {$e->getMessage()}");
            Log::warning('synapcores:train | CREATE EXPERIMENT failed', ['error' => $e->getMessage()]);
            return null;
        }

        // ── Step 3: DEPLOY MODEL ─────────────────────────────────────────────
        $this->info('  [3/4] DEPLOY MODEL churn_predictor…');
        try {
            $this->synapcores->executeAutoML(
                'DEPLOY MODEL churn_predictor FROM EXPERIMENT churn_v1',
                self::AUTOML_TIMEOUT,
            );
            $this->line('        Model deployed.');
        } catch (\Throwable $e) {
            $this->warn("        Failed: {$e->getMessage()}");
            Log::warning('synapcores:train | DEPLOY MODEL failed', ['error' => $e->getMessage()]);
            return null;
        }

        // ── Step 4: PREDICT ──────────────────────────────────────────────────
        $this->info('  [4/4] PREDICT churn_probability…');

        return $this->predictViaSQL($total);
    }

    /**
     * Run the PREDICT SQL, store results in a temp table, read them back.
     *
     * @return array<int, float>|null
     */
    private function predictViaSQL(int $total): ?array
    {
        // Store predictions in a dedicated table so we can SELECT them back
        try {
            $this->synapcores->execute('DROP TABLE IF EXISTS churn_predictions');
        } catch (SynapCoresException $e) {
            Log::warning('synapcores:train | DROP churn_predictions warning', ['error' => $e->getMessage()]);
        }

        try {
            $this->synapcores->executeAutoML(<<<SQL
                CREATE TABLE churn_predictions AS
                PREDICT churn_probability USING churn_predictor AS
                SELECT id, tier, tenure_months, visits_30d, spend_30d
                FROM loyalty_members
            SQL, self::AUTOML_TIMEOUT);
        } catch (\Throwable $e) {
            $this->warn("  PREDICT failed: {$e->getMessage()}");
            Log::warning('synapcores:train | PREDICT failed', ['error' => $e->getMessage()]);
            return null;
        }

        // Read predictions back (id → churn_probability)
        try {
            $rows = $this->synapcores->query(
                'SELECT id, churn_probability FROM churn_predictions ORDER BY id'
            );
        } catch (\Throwable $e) {
            $this->warn("  Reading predictions failed: {$e->getMessage()}");
            Log::warning('synapcores:train | read churn_predictions failed', ['error' => $e->getMessage()]);
            return null;
        }

        $scores = [];
        foreach ($rows as $row) {
            $id    = (int)   ($row['id']                ?? 0);
            $score = (float) ($row['churn_probability'] ?? 0.5);
            if ($id > 0) {
                $scores[$id] = $score;
            }
        }

        if (empty($scores)) {
            $this->warn('  No predictions returned.');
            return null;
        }

        $this->line("  AutoML: " . count($scores) . " predictions.");
        Log::info('synapcores:train | AutoML scoring done', ['scored' => count($scores)]);

        return $scores;
    }

    /**
     * Sync local SQLite loyalty_members → SynapCores.
     * Returns true if at least one row was confirmed written.
     */
    private function syncToSynapCores(int $total): bool
    {
        try {
            $this->synapcores->execute('DROP TABLE IF EXISTS loyalty_members');
            $responseCreateTable = $this->synapcores->execute(
                'CREATE TABLE loyalty_members (id INTEGER PRIMARY KEY, tier TEXT, tenure_months INTEGER, visits_30d INTEGER, spend_30d REAL, churned BOOLEAN)'
            );
            $this->info(json_encode($responseCreateTable, JSON_PRETTY_PRINT));
        } catch (SynapCoresException $e) {
            $this->warn("  CREATE TABLE: {$e->getMessage()} — continuing.");
            Log::warning('synapcores:train | CREATE TABLE error', ['error' => $e->getMessage()]);
        }

        $bar = $this->output->createProgressBar($total);
        $inserted = 0;
        $failed   = 0;

        $bar->start();

        LoyaltyMember::select(['id', 'tier', 'tenure_months', 'visits_30d', 'spend_30d', 'churned'])
            ->orderBy('id')
            ->chunk(100, function ($members) use ($bar, &$inserted, &$failed) {
                $statements = $members->map(function ($m) {
                    $tier     = str_replace("'", "''", $m->tier->value);
                    $churnInt = $m->churned ? 1 : 0;
                    return "INSERT INTO loyalty_members (id, tier, tenure_months, visits_30d, spend_30d, churned)"
                        . " VALUES ({$m->id}, '{$tier}', {$m->tenure_months}, {$m->visits_30d}, {$m->spend_30d}, {$churnInt})";
                })->all();

                $results = $this->synapcores->batch($statements);

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
        $this->line("  Sync: {$inserted} rows written, {$failed} failed.");
        Log::info('synapcores:train | sync done', compact('inserted', 'failed'));

        if ($inserted === 0) {
            $this->warn('  No rows reached SynapCores — AutoML path aborted.');
            return false;
        }

        return true;
    }

    // ─── Embeddings fallback ──────────────────────────────────────────────────

    /**
     * Cosine similarity over SynapCores AI Embeddings.
     * Reads features from local SQLite — no SynapCores table required.
     *
     * @return array<int, float>|null
     */
    private function tryEmbeddingsPath(int $total): ?array
    {
        $this->info('[1/2] Loading prototype embeddings from SynapCores…');

        try {
            $prototypes = $this->getPrototypeEmbeddings();
        } catch (\Throwable $e) {
            $this->error("  Embeddings unavailable: {$e->getMessage()}");
            return null;
        }

        $this->line('  Prototypes loaded (' . count($prototypes[0]) . '-dim embeddings).');
        $this->info('[2/2] Scoring members via batch embeddings…');

        $scores = [];
        $failed = 0;
        $bar    = $this->output->createProgressBar($total);
        $bar->start();

        DB::table('loyalty_members')
            ->select(['id', 'tier', 'tenure_months', 'visits_30d', 'spend_30d'])
            ->orderBy('id')
            ->chunk(100, function ($members) use ($prototypes, $bar, &$scores, &$failed) {
                try {
                    $texts = $members->map(fn($m) =>
                        "Loyalty member tier {$m->tier}, {$m->tenure_months} months, "
                        . "{$m->visits_30d} visits, \${$m->spend_30d} spend last 30 days"
                    )->values()->all();

                    $embeddings = $this->synapcores->batchEmbeddings($texts);

                    foreach ($members->values() as $i => $m) {
                        $emb = $embeddings[$i] ?? null;
                        if ($emb !== null) {
                            $scores[$m->id] = $this->churnProbability($emb, $prototypes);
                        } else {
                            $failed++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('synapcores:train | embeddings batch failed', ['error' => $e->getMessage()]);
                    $failed += count($members);
                }

                $bar->advance(count($members));
            });

        $bar->finish();
        $this->newLine();

        if (empty($scores)) {
            return null;
        }

        $this->line('  Embeddings: ' . count($scores) . " scored, {$failed} failed.");
        Log::info('synapcores:train | embeddings path done', ['scored' => count($scores), 'failed' => $failed]);

        return $scores;
    }

    /** @return array{0: float[], 1: float[]} */
    private function getPrototypeEmbeddings(): array
    {
        $embs = $this->synapcores->batchEmbeddings([
            'Customer who churned: inactive, stopped visiting, no spending, cancelled membership, at high risk of leaving',
            'Loyal active customer: frequent visits, consistent spending, long tenure, highly engaged, low churn risk',
        ]);

        if (count($embs) < 2) {
            throw new \RuntimeException('Expected 2 prototype embeddings, got ' . count($embs));
        }

        return [$embs[0], $embs[1]];
    }

    /**
     * @param float[]                       $emb
     * @param array{0: float[], 1: float[]} $prototypes
     */
    private function churnProbability(array $emb, array $prototypes): float
    {
        $simChurned = $this->cosineSim($emb, $prototypes[0]);
        $simActive  = $this->cosineSim($emb, $prototypes[1]);
        $total      = $simChurned + $simActive;

        return $total > 0 ? $simChurned / $total : 0.5;
    }

    /** @param float[] $a  @param float[] $b */
    private function cosineSim(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $dims = min(count($a), count($b));

        for ($i = 0; $i < $dims; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] ** 2;
            $nb  += $b[$i] ** 2;
        }

        $denom = sqrt($na) * sqrt($nb);
        return $denom > 0 ? $dot / $denom : 0.0;
    }

    // ─── Persistence ──────────────────────────────────────────────────────────

    /**
     * Normalise raw scores to [0.05, 0.95] and write churn_probability to SQLite.
     *
     * @param array<int, float> $scores
     */
    private function saveScores(array $scores): void
    {
        $this->info('Normalising and saving scores to local database…');

        $min   = min($scores);
        $max   = max($scores);
        $range = $max - $min ?: 1;

        $scored = 0;
        DB::transaction(function () use ($scores, $min, $range, &$scored) {
            foreach ($scores as $id => $raw) {
                $prob = 0.05 + (($raw - $min) / $range) * 0.90;
                DB::table('loyalty_members')
                    ->where('id', $id)
                    ->update(['churn_probability' => round($prob, 4)]);
                $scored++;
            }
        });

        $total = DB::table('loyalty_members')->count();
        $this->info("Done. {$scored}/{$total} members scored — dashboard is ready.");
        Log::info('synapcores:train finished', ['scored' => $scored, 'total' => $total]);
    }
}
