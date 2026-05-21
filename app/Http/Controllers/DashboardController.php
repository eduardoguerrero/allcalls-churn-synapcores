<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\AtRiskMemberData;
use App\Repositories\LoyaltyMemberRepositoryInterface;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(private readonly LoyaltyMemberRepositoryInterface $members)
    { }

    public function index()
    {
        $members = $this->members
            ->getAtRisk(LoyaltyMemberRepositoryInterface::AT_RISK_LIMIT)
            ->map(fn($member) => AtRiskMemberData::fromModel($member));

        $avg  = round((float) $members->avg('churnProbability') * 100, 1);
        $high = $members->where('churnProbability', '>=', 0.7)->count();

        Log::info('Dashboard at-risk members loaded', ['count' => $members->count()]);

        return view('dashboard.index', compact('members', 'avg', 'high'));
    }
}
