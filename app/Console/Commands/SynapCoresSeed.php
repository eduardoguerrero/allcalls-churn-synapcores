<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Tier;
use App\Models\LoyaltyMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SynapCoresSeed extends Command
{
    protected $signature   = 'synapcores:seed {--count=8000 : Number of members to generate}';
    protected $description = 'Seed loyalty_members with realistic churn-signal data';

    // Tier distribution that mirrors a real loyalty program pyramid (weights are relative, not percentages)
    private const array WEIGHTS = [50, 30, 15, 5];

    public function handle(): int
    {

        $this->info("Starting command synapcores:seed...");
        $count = (int) $this->option('count');

        if ($count < 1 || $count > 100_000) {
            $this->error('--count must be between 1 and 100,000');
            return self::FAILURE;
        }

        $this->info("Seeding {$count} loyalty members...");
        Log::info('synapcores:seed started', ['count' => $count]);

        LoyaltyMember::truncate();
        Log::info('synapcores:seed | loyalty_members table truncated');

        $now = now();

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            $rows = [];

            foreach ($chunk as $_) {
                $visits  = random_int(0, 20);
                $spend   = round(random_int(0, 50000) / 100, 2);

                $rows[] = [
                    'tier'              => $this->weightedRandom(Tier::cases(), self::WEIGHTS)->value,
                    'tenure_months'     => random_int(1, 84),
                    'visits_30d'        => $visits,
                    'spend_30d'         => $spend,
                    'last_visit_at'     => $now->copy()->subDays(random_int(0, 90)),
                    'churned'           => $this->computeChurn($visits, $spend),
                    'churn_probability' => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }

            LoyaltyMember::insert($rows);
            $bar->advance(count($chunk));
        }

        $bar->finish();
        $this->newLine();

        $total   = LoyaltyMember::count();
        $churned = LoyaltyMember::where('churned', true)->count();

        $this->info("Done. {$total} members seeded.");
        Log::info('synapcores:seed finished', [
            'total'      => $total,
            'churned'    => $churned,          
        ]);

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
