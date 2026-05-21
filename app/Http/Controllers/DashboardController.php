<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Repositories\LoyaltyMemberRepositoryInterface;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    public function __construct(private readonly LoyaltyMemberRepositoryInterface $members) 
    { }

    public function index()
    {
        $members = $this->members->getAtRisk(LoyaltyMemberRepositoryInterface::AT_RISK_LIMIT);

        Log::info('Dashboard at-risk members loaded', ['count' => $members->count()]);

        return view('dashboard.index', compact('members'));
    }
}
