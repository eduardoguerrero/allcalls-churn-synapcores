<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Tier;
use App\Models\LoyaltyMember;
use App\Services\SynapCores\Exceptions\SynapCoresException;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SynapCoresSeed extends Command
{
    protected $signature   = 'synapcores:seed {--count=8000 : Number of members to generate}';
    protected $description = 'Seed loyalty_members locally and sync to SynapCores';

    private const array WEIGHTS = [50, 30, 15, 5];

    public function __construct(private readonly SynapCoresClient $synapcores)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $count = (int) $this->option('count');

        if ($count < 1 || $count > 100_000) {
            $this->error('--count must be between 1 and 100,000');
            return self::FAILURE;
        }

        $this->info("Seeding {$count} loyalty members…");
        Log::info('synapcores:seed started', ['count' => $count]);

        // Local SQLite (for dashboard reads) --------------------------------------------
        LoyaltyMember::truncate();

        $now     = now();
        $rows    = [];
        $churned = 0;

        for ($id = 1; $id <= $count; $id++) {
            $visits  = random_int(0, 20);
            $spend   = round(random_int(0, 50000) / 100, 2);
            $isChurn = $this->computeChurn($visits, $spend);

            $rows[] = [
                'tier'              => $this->weightedRandom(Tier::cases(), self::WEIGHTS)->value,
                'tenure_months'     => random_int(1, 84),
                'visits_30d'        => $visits,
                'spend_30d'         => $spend,
                'last_visit_at'     => $now->copy()->subDays(random_int(0, 90)),
                'churned'           => $isChurn,
                'churn_probability' => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];

            if ($isChurn) {
                $churned++;
            }

            if (count($rows) === 500) {
                LoyaltyMember::insert($rows);
                $rows = [];
            }
        }

        if ($rows) {
            LoyaltyMember::insert($rows);
        }

        $this->info("Local DB: {$count} members inserted ({$churned} churned).");
        Log::info('synapcores:seed local done', compact('count', 'churned'));

        // SynapCores sync --------------------------------------------
        $this->syncToSynapCores($count);

        return self::SUCCESS;
    }

    private function syncToSynapCores(int $total): void
    {
        $this->info("Syncing {$total} members to SynapCores…");

        try {
            $this->synapcores->execute('DROP TABLE IF EXISTS loyalty_members');
        } catch (SynapCoresException $e) {
            Log::warning('synapcores:seed | DROP TABLE error (ignored)', ['status' => $e->getStatusCode()]);
        }

        try {
            $this->synapcores->execute(<<<SQL
                CREATE TABLE loyalty_members (
                    id                INTEGER PRIMARY KEY,
                    tier              TEXT    NOT NULL,
                    tenure_months     INTEGER NOT NULL,
                    visits_30d        INTEGER NOT NULL,
                    spend_30d         REAL    NOT NULL,
                    churned           INTEGER NOT NULL,
                    churn_probability REAL
                )
            SQL);
        } catch (SynapCoresException $e) {
            $this->warn("SynapCores CREATE TABLE: {$e->getMessage()}");
            Log::warning('synapcores:seed | CREATE TABLE error', ['error' => $e->getMessage()]);
        }

        $bar              = $this->output->createProgressBar($total);
        $bar->start();

        $inserted         = 0;
        $reportedFailures = 0;

        LoyaltyMember::select(['id', 'tier', 'tenure_months', 'visits_30d', 'spend_30d', 'churned'])
            ->orderBy('id')
            ->chunk(100, function ($members) use ($bar, &$inserted, &$reportedFailures) {
                $statements = $members->map(function ($m) {
                    $tier     = str_replace("'", "''", $m->tier->value);
                    $churnInt = $m->churned ? 1 : 0;
                    return "INSERT INTO loyalty_members (id, tier, tenure_months, visits_30d, spend_30d, churned, churn_probability)"
                        . " VALUES ({$m->id}, '{$tier}', {$m->tenure_months}, {$m->visits_30d}, {$m->spend_30d}, {$churnInt}, NULL)";
                })->all();

                $results = $this->synapcores->batch($statements);
                $retries = [];

                foreach ($results as $i => $result) {
                    if (($result['rows_affected'] ?? 0) === 1) {
                        $inserted++;
                    } else {
                        $retries[] = $statements[$i];
                    }
                }

                foreach ($retries as $sql) {
                    try {
                        $this->synapcores->execute($sql);
                        $inserted++;
                    } catch (\Throwable) {
                        $reportedFailures++;
                    }
                }

                $bar->advance(count($members));
            });

        $bar->finish();
        $this->newLine();

        $this->info("SynapCores: {$inserted} rows confirmed, {$reportedFailures} failed.");
        Log::info('synapcores:seed sync done', compact('inserted', 'reportedFailures'));
    }

    /**
     * Signal:
     *  - Low visits (<2)  AND low spend (<$20)   → ~85% churned
     *  - High visits (>5) OR  high spend (>$100) → ~10% churned
     *  - Mid range                                → ~40% churned
     */
    private function computeChurn(int $visits, float $spend): bool
    {
        $roll = random_int(1, 100);

        if ($visits < 2 && $spend < 20.0) {
            return $roll <= 85;
        }

        if ($visits > 5 || $spend > 100.0) {
            return $roll <= 10;
        }

        return $roll <= 40;
    }

    /** @param Tier[] $items  @param int[] $weights */
    private function weightedRandom(array $items, array $weights): Tier
    {
        $total      = array_sum($weights);
        $roll       = random_int(1, $total);
        $cumulative = 0;

        foreach ($items as $i => $item) {
            $cumulative += $weights[$i];
            if ($roll <= $cumulative) {
                return $item;
            }
        }

        return $items[array_key_last($items)];
    }
}
