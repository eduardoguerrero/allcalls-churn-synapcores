<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LoyaltyMember;
use Illuminate\Database\Eloquent\Collection;

class EloquentLoyaltyMemberRepository implements LoyaltyMemberRepositoryInterface
{
    public function getAtRisk(int $limit = 50): Collection
    {
        return LoyaltyMember::atRisk()->take($limit)->get();
    }
}
