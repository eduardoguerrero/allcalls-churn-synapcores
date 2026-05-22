<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LoyaltyMember;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentLoyaltyMemberRepository implements LoyaltyMemberRepositoryInterface
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
        $min      = min($scores);
        $range    = max($scores) - $min ?: 1;
        $scoredAt = now();

        $updates     = [];
        $predictions = [];

        foreach ($scores as $id => $raw) {
            $prob = round(0.05 + (($raw - $min) / $range) * 0.90, 4);

            $updates[] = ['id' => $id, 'churn_probability' => $prob];

            $predictions[] = [
                'member_id'         => $id,
                'churn_probability' => $prob,
                'scored_at'         => $scoredAt,
                'created_at'        => $scoredAt,
                'updated_at'        => $scoredAt,
            ];
        }

        DB::transaction(function () use ($updates, $predictions) {
            // SQLite rejects upsert() when NOT NULL columns are absent from the INSERT columns list,
            // even when the ON CONFLICT DO UPDATE path would be taken. Use CASE WHEN bulk UPDATE instead.
            foreach (array_chunk($updates, 500) as $chunk) {
                $ids   = implode(',', array_column($chunk, 'id'));
                $cases = implode(' ', array_map(
                    fn($row) => "WHEN {$row['id']} THEN {$row['churn_probability']}",
                    $chunk,
                ));
                DB::statement("UPDATE loyalty_members SET churn_probability = CASE id {$cases} END WHERE id IN ({$ids})");
            }

            foreach (array_chunk($predictions, 500) as $chunk) {
                DB::table('churn_predictions')->insert($chunk);
            }
        });

        return count($updates);
    }
}
