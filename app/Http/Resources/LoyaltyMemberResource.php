<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tier' => $this->tier->value,
            'tenure_months' => $this->tenure_months,
            'visits_30d' => $this->visits_30d,
            'spend_30d' => round((float)$this->spend_30d, 2),
            'last_visit_at' => $this->last_visit_at?->toDateTimeString(),
            'churned' => $this->churned,
            'churn_probability' => round((float)$this->churn_probability, 4),
        ];
    }
}
