<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\LoyaltyMember;

readonly class AtRiskMemberData
{
    public function __construct(
        public int    $id,
        public string $tier,
        public int    $tenureMonths,
        public int    $visits30d,
        public float  $spend30d,
        public float  $churnProbability,
    ) {}

    public static function fromModel(LoyaltyMember $member): self
    {
        return new self(
            id:               $member->id,
            tier:             $member->tier->value,
            tenureMonths:     $member->tenure_months,
            visits30d:        $member->visits_30d,
            spend30d:         round((float) $member->spend_30d, 2),
            churnProbability: round((float) $member->churn_probability, 4),
        );
    }
}
