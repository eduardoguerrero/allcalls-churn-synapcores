<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Tier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LoyaltyMember extends Model
{
    protected $fillable = [
        'tier',
        'tenure_months',
        'visits_30d',
        'spend_30d',
        'last_visit_at',
        'churned',
        'churn_probability',
    ];

    protected $casts = [
        'tier' => Tier::class,
        'churned' => 'boolean',
        'churn_probability' => 'float',
        'spend_30d' => 'float',
        'last_visit_at' => 'datetime',
    ];

    /** Top at-risk Gold/Platinum members, ordered by predicted churn probability */
    public function scopeAtRisk(Builder $query): Builder
    {
        return $query
            ->whereIn('tier', [Tier::Gold->value, Tier::Platinum->value])
            ->whereNotNull('churn_probability')
            ->orderByDesc('churn_probability');
    }
}
