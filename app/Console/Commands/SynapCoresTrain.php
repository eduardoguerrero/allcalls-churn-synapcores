<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SynapCoresTrain extends Command
{
    protected $signature   = 'synapcores:train';
    protected $description = 'Score churn probability for all members using SynapCores AI Embeddings';

    public function __construct(
        private readonly SynapCoresClient $synapcores,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        Log::info('synapcores:train started');

        $total = DB::table('loyalty_members')->count();
        $this->info("Scoring {$total} members via SynapCores AI Embeddings…");

        if ($total === 0) {
            $this->error('No members found. Run php artisan synapcores:seed first.');
            return self::FAILURE;
        }

        // Step 1 — get prototype embeddings from SynapCores (the "model")
        $this->info('[1/2] Loading churn model prototypes from SynapCores…');

        try {
            $prototypes = $this->getPrototypeEmbeddings();
        } catch (\Throwable $e) {
            $this->error("SynapCores embeddings unavailable: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->line('  Prototypes loaded (' . count($prototypes[0]) . '-dim embeddings).');

        // Step 2 — score every member in batches
        $this->info('[2/2] Scoring members via SynapCores batch embeddings…');

        $bar     = $this->output->createProgressBar($total);
        $bar->start();

        $scores = [];  // id => raw score
        $failed = 0;

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

                    foreach ($members as $i => $m) {
                        $emb = $embeddings[$i] ?? null;
                        if ($emb !== null) {
                            $scores[$m->id] = $this->churnProbability($emb, $prototypes);
                        } else {
                            $failed++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('synapcores:train | batch failed', ['error' => $e->getMessage()]);
                    $failed += count($members);
                }

                $bar->advance(count($members));
            });

        $bar->finish();
        $this->newLine();

        // Normalise raw cosine scores to [0.05, 0.95] so the dashboard shows
        // meaningful differentiation. The relative ranking (SynapCores-derived)
        // is preserved; only the visual scale changes.
        $this->info('Normalising and saving scores…');
        $min    = min($scores);
        $max    = max($scores);
        $range  = $max - $min ?: 1;
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

        $this->info("Done. {$scored} members scored, {$failed} failed.");
        Log::info('synapcores:train finished', compact('scored', 'failed', 'total'));

        return self::SUCCESS;
    }

    /**
     * Get two prototype embeddings from SynapCores:
     *   [0] = churned customer prototype
     *   [1] = active customer prototype
     *
     * @return array{0: float[], 1: float[]}
     */
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
     * Compute churn probability as normalised cosine similarity to churned prototype.
     *
     * @param float[] $emb
     * @param array{0: float[], 1: float[]} $prototypes
     */
    private function churnProbability(array $emb, array $prototypes): float
    {
        $simChurned = $this->cosineSim($emb, $prototypes[0]);
        $simActive  = $this->cosineSim($emb, $prototypes[1]);
        $total      = $simChurned + $simActive;

        if ($total <= 0) {
            return 0.5;
        }

        return $simChurned / $total;
    }

    /** @param float[] $a @param float[] $b */
    private function cosineSim(array $a, array $b): float
    {
        $dot  = 0.0;
        $na   = 0.0;
        $nb   = 0.0;
        $dims = min(count($a), count($b));

        for ($i = 0; $i < $dims; $i++) {
            $dot += $a[$i] * $b[$i];
            $na  += $a[$i] ** 2;
            $nb  += $b[$i] ** 2;
        }

        $denom = sqrt($na) * sqrt($nb);
        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
