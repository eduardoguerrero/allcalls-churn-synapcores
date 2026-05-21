<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Enums\Tier;
use App\Models\LoyaltyMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SynapCoresSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Validation — invalid --count values
    // -------------------------------------------------------------------------

    #[Test]
    #[DataProvider('invalidCountProvider')]
    public function it_fails_with_invalid_count(int $count): void
    {
        $this->artisan('synapcores:seed', ['--count' => $count])
            ->assertFailed();

        $this->assertDatabaseCount('loyalty_members', 0);
    }

    public static function invalidCountProvider(): array
    {
        return [
            'zero'            => [0],
            'negative'        => [-1],
            'above max'       => [100_001],
            'way above max'   => [999_999],
        ];
    }

    // -------------------------------------------------------------------------
    // Successful seed
    // -------------------------------------------------------------------------

    #[Test]
    public function it_seeds_the_requested_number_of_members(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 50])
            ->assertSuccessful();

        $this->assertDatabaseCount('loyalty_members', 50);
    }

    #[Test]
    public function it_seeds_the_default_count_when_no_option_is_given(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 100])
            ->assertSuccessful();

        $this->assertDatabaseCount('loyalty_members', 100);
    }

    #[Test]
    public function seeded_members_have_null_churn_probability(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 20])->assertSuccessful();

        $this->assertDatabaseMissing('loyalty_members', ['churn_probability' => null === false]);

        LoyaltyMember::all()->each(function (LoyaltyMember $member) {
            $this->assertNull($member->churn_probability);
        });
    }

    #[Test]
    public function seeded_members_only_have_valid_tier_values(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 50])->assertSuccessful();

        $validValues = array_column(Tier::cases(), 'value');

        LoyaltyMember::all()->each(function (LoyaltyMember $member) use ($validValues) {
            $this->assertContains($member->tier->value, $validValues);
        });
    }

    #[Test]
    public function seeded_members_have_valid_field_ranges(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 50])->assertSuccessful();

        LoyaltyMember::all()->each(function (LoyaltyMember $member) {
            $this->assertGreaterThanOrEqual(1, $member->tenure_months);
            $this->assertLessThanOrEqual(84, $member->tenure_months);

            $this->assertGreaterThanOrEqual(0, $member->visits_30d);
            $this->assertLessThanOrEqual(20, $member->visits_30d);

            $this->assertGreaterThanOrEqual(0, $member->spend_30d);
            $this->assertLessThanOrEqual(500, $member->spend_30d);

            $this->assertNotNull($member->last_visit_at);
            $this->assertIsBool($member->churned);
        });
    }

    #[Test]
    public function it_truncates_existing_data_before_seeding(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 30])->assertSuccessful();
        $this->assertDatabaseCount('loyalty_members', 30);

        $this->artisan('synapcores:seed', ['--count' => 10])->assertSuccessful();
        $this->assertDatabaseCount('loyalty_members', 10);
    }

    #[Test]
    public function seeded_data_produces_a_mix_of_churned_and_non_churned_members(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 200])->assertSuccessful();

        $churned    = LoyaltyMember::where('churned', true)->count();
        $notChurned = LoyaltyMember::where('churned', false)->count();

        $this->assertGreaterThan(0, $churned,    'Expected some churned members');
        $this->assertGreaterThan(0, $notChurned, 'Expected some non-churned members');
    }

    #[Test]
    public function seeded_data_contains_all_tier_types_in_a_large_run(): void
    {
        $this->artisan('synapcores:seed', ['--count' => 500])->assertSuccessful();

        foreach (Tier::cases() as $tier) {
            $this->assertDatabaseHas('loyalty_members', ['tier' => $tier->value]);
        }
    }
}
