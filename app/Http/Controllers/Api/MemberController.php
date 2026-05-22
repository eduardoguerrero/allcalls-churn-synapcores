<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LoyaltyMemberResource;
use App\Models\LoyaltyMember;
use App\Repositories\LoyaltyMemberRepositoryInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class MemberController extends Controller
{
    public function __construct(private readonly LoyaltyMemberRepositoryInterface $members)
    {
    }

    public function atRisk(): AnonymousResourceCollection
    {
        $members = $this->members->getAtRisk();

        return LoyaltyMemberResource::collection($members);
    }

    public function sendOffer(LoyaltyMember $member): JsonResponse
    {
        Log::info('Retention offer triggered', [
            'member_id' => $member->id,
            'tier' => $member->tier->value,
            'churn_p' => $member->churn_probability,
        ]);

        return response()->json(['status' => 'offer_logged', 'member_id' => $member->id]);
    }
}
