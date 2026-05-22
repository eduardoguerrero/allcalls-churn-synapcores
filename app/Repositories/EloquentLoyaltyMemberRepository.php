<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LoyaltyMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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
                    $q->orWhere('id', (int)$term);
                }
            });
        }

        return $query->paginate(LoyaltyMemberRepositoryInterface::PAGE_SIZE);
    }

    public function saveChurnScores(array $scores): int
    {
        $min   = min($scores);
        $range = max($scores) - $min ?: 1;
        $saved = 0;

        DB::transaction(function () use ($scores, $min, $range, &$saved) {
            foreach ($scores as $id => $raw) {
                $prob = 0.05 + (($raw - $min) / $range) * 0.90;
                DB::table('loyalty_members')
                    ->where('id', $id)
                    ->update(['churn_probability' => round($prob, 4)]);
                $saved++;
            }
        });

        return $saved;
    }
}
