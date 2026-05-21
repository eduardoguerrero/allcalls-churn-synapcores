<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MemberController extends Controller
{
    public function atRisk(): JsonResponse
    {
        $members = LoyaltyMember::atRisk()->take(50)->get([
            'id', 'tier', 'tenure_months', 'visits_30d', 'spend_30d',
            'last_visit_at', 'churned', 'churn_probability',
        ]);

        return response()->json($members);
    }

    public function sendOffer(LoyaltyMember $member): JsonResponse
    {
        Log::info('Retention offer triggered', [
            'member_id' => $member->id,
            'tier'      => $member->tier,
            'churn_p'   => $member->churn_probability,
        ]);

        return response()->json(['status' => 'offer_logged', 'member_id' => $member->id]);
    }
}
