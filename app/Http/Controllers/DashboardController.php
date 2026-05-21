<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyMember;

class DashboardController extends Controller
{
    public function index()
    {
        $members = LoyaltyMember::atRisk()->take(50)->get();

        return view('dashboard.index', compact('members'));
    }
}
