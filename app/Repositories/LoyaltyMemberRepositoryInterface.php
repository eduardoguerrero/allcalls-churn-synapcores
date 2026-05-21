<?php

declare(strict_types=1);

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface LoyaltyMemberRepositoryInterface
{
    public const int PAGE_SIZE = 10;

    public function getAtRisk(?string $search = null): LengthAwarePaginator;
}
