<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LoyaltyMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentLoyaltyMemberRepository implements LoyaltyMemberRepositoryInterface
{
    public function getAtRisk(?string $search = null): LengthAwarePaginator
    {
        $query = LoyaltyMember::atRisk();

        if ($search !== null && $search !== '') {
            $term = strtolower(trim($search));
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(tier) LIKE ?', ["%{$term}%"]);
                if (is_numeric($term)) {
                    $q->orWhere('id', (int) $term);
                }
            });
        }

        return $query->paginate(LoyaltyMemberRepositoryInterface::PAGE_SIZE);
    }
}
