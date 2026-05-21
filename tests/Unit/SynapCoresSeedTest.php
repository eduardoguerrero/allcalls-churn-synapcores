<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\Commands\SynapCoresSeed;
use App\Enums\Tier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class SynapCoresSeedTest extends TestCase
{
    private SynapCoresSeed $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new SynapCoresSeed();
    }

    // -------------------------------------------------------------------------
    // computeChurn
    // -------------------------------------------------------------------------

    #[Test]
    public function low_visits_and_low_spend_churns_at_high_rate(): void
    {
        $results = $this->runComputeChurn(visits: 0, spend: 5.0, times: 500);

        // Expect ~85% churn — allow ±10% tolerance
        $this->assertGreaterThan(0.75, $results['rate'], 'Expected churn rate > 75% for low-risk zone');
    }

    #[Test]
    public function high_visits_churns_at_low_rate(): void
    {
        $results = $this->runComputeChurn(visits: 10, spend: 50.0, times: 500);

        // Expect ~10% churn — allow ±10% tolerance
        $this->assertLessThan(0.20, $results['rate'], 'Expected churn rate < 20% for high-activity zone');
    }

    #[Test]
    public function high_spend_churns_at_low_rate(): void
    {
        $results = $this->runComputeChurn(visits: 2, spend: 200.0, times: 500);

        $this->assertLessThan(0.20, $results['rate'], 'Expected churn rate < 20% for high-spend zone');
    }

    #[Test]
    public function mid_range_activity_churns_at_medium_rate(): void
    {
        $results = $this->runComputeChurn(visits: 3, spend: 50.0, times: 500);

        // Expect ~40% churn — allow ±15% tolerance
        $this->assertGreaterThan(0.25, $results['rate']);
        $this->assertLessThan(0.55, $results['rate']);
    }

    #[Test]
    #[DataProvider('boundaryProvider')]
    public function boundary_values_use_correct_zone(int $visits, float $spend, float $minRate, float $maxRate): void
    {
        $results = $this->runComputeChurn($visits, $spend, times: 300);

        $this->assertGreaterThan($minRate, $results['rate'], "Rate {$results['rate']} below min {$minRate}");
        $this->assertLessThan($maxRate, $results['rate'], "Rate {$results['rate']} above max {$maxRate}");
    }

    public static function boundaryProvider(): array
    {
        return [
            'exactly at low threshold (1 visit, $19.99)'  => [1,  19.99, 0.70, 1.00],
            'exactly at high threshold (6 visits, $50)'   => [6,  50.00, 0.00, 0.20],
            'exactly at high spend ($100.01)'              => [3, 100.01, 0.00, 0.20],
        ];
    }

    // -------------------------------------------------------------------------
    // weightedRandom
    // -------------------------------------------------------------------------

    #[Test]
    public function weighted_random_returns_a_tier_instance(): void
    {
        $result = $this->callWeightedRandom(Tier::cases(), [50, 30, 15, 5]);

        $this->assertInstanceOf(Tier::class, $result);
    }

    #[Test]
    public function weighted_random_with_single_item_always_returns_that_item(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $result = $this->callWeightedRandom([Tier::Gold], [100]);
            $this->assertSame(Tier::Gold, $result);
        }
    }

    #[Test]
    public function weighted_random_only_returns_valid_tier_values(): void
    {
        $validValues = array_column(Tier::cases(), 'value');

        for ($i = 0; $i < 100; $i++) {
            $result = $this->callWeightedRandom(Tier::cases(), [50, 30, 15, 5]);
            $this->assertContains($result->value, $validValues);
        }
    }

    #[Test]
    public function weighted_random_respects_weight_distribution(): void
    {
        $counts = array_fill_keys(array_column(Tier::cases(), 'value'), 0);

        for ($i = 0; $i < 1000; $i++) {
            $tier = $this->callWeightedRandom(Tier::cases(), [50, 30, 15, 5]);
            $counts[$tier->value]++;
        }

        // Bronze (weight 50) should appear more than Platinum (weight 5)
        $this->assertGreaterThan($counts['Platinum'], $counts['Bronze']);
        $this->assertGreaterThan($counts['Gold'], $counts['Silver']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function runComputeChurn(int $visits, float $spend, int $times): array
    {
        $method = new ReflectionMethod(SynapCoresSeed::class, 'computeChurn');
        $churned = 0;

        for ($i = 0; $i < $times; $i++) {
            if ($method->invoke($this->command, $visits, $spend)) {
                $churned++;
            }
        }

        return ['churned' => $churned, 'rate' => $churned / $times];
    }

    private function callWeightedRandom(array $items, array $weights): Tier
    {
        $method = new ReflectionMethod(SynapCoresSeed::class, 'weightedRandom');

        return $method->invoke($this->command, $items, $weights);
    }
}
