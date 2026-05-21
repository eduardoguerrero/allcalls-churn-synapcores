<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Database\Eloquent\Collection;

interface LoyaltyMemberRepositoryInterface
{
    public const int AT_RISK_LIMIT = 50;

    public function getAtRisk(int $limit = self::AT_RISK_LIMIT): Collection;
}
