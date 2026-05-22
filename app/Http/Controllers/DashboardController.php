<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\DashboardRequest;
use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function index(DashboardRequest $request): View
    {
        $search = $request->search();

        $data = $this->dashboard->atRiskSummary($search);

        return view('dashboard.index', [...$data, 'search' => $search]);
    }
}
