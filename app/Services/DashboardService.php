<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AtRiskMemberData;
use App\Models\LoyaltyMember;
use App\Repositories\LoyaltyMemberRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(private readonly LoyaltyMemberRepositoryInterface $members) 
    { }

    /**
     * @return array{paginator: LengthAwarePaginator, members: Collection<int, AtRiskMemberData>, avg: float, high: int}
     */
    public function atRiskSummary(?string $search): array
    {
        $paginator = $this->members->getAtRisk($search);

        $items = collect($paginator->items())
            ->map(fn(LoyaltyMember $member) => AtRiskMemberData::fromModel($member));

        return [
            'paginator' => $paginator,
            'members'   => $items,
            'avg'       => round((float) $items->avg('churnProbability') * 100, 1),
            'high'      => $items->where('churnProbability', '>=', 0.7)->count(),
        ];
    }
}
