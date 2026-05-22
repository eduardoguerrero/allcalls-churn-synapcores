<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Tier;
use App\Models\LoyaltyMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SynapCoresSeed extends Command
{
    protected $signature = 'synapcores:seed {--count=8000 : Number of members to generate}';
    protected $description = 'Seed loyalty_members in the local database with a realistic churn signal';

    private const WEIGHTS = [50, 30, 15, 5];

    public function handle(): int
    {
        $count = (int)$this->option('count');

        if ($count < 1 || $count > 10000) {
            $this->error('--count must be between 1 and 10,000');
            return self::FAILURE;
        }

        $this->info("Seeding {$count} loyalty_members local table...");
        Log::info('synapcores:seed started', ['count' => $count]);

        LoyaltyMember::truncate();

        $now = now();
        $rows = [];
        $churned = 0;

        for ($id = 1; $id <= $count; $id++) {
            $visits = random_int(0, 20);
            $spend = round(random_int(0, 50000) / 100, 2);
            $isChurn = $this->computeChurn($visits, $spend);

            $rows[] = [
                'tier' => $this->weightedRandom(Tier::cases(), self::WEIGHTS)->value,
                'tenure_months' => random_int(1, 84),
                'visits_30d' => $visits,
                'spend_30d' => $spend,
                'last_visit_at' => $now->copy()->subDays(random_int(0, 90)),
                'churned' => $isChurn,
                'churn_probability' => null,
                'created_at' => $now,
                'updated_at' => $now,
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

        $this->info("Done. {$count} members inserted ({$churned} churned).");
        $this->line("Run <info>php artisan synapcores:train</info> to score churn probabilities.");
        Log::info('synapcores:seed done', compact('count', 'churned'));

        return self::SUCCESS;
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

    private function weightedRandom(array $items, array $weights): Tier
    {
        $total = array_sum($weights);
        $roll = random_int(1, $total);
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
