<?php

namespace App\Models;

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
        'churned'           => 'boolean',
        'churn_probability' => 'float',
        'spend_30d'         => 'float',
        'last_visit_at'     => 'datetime',
    ];

    /** Top at-risk Gold/Platinum members, ordered by predicted churn probability */
    public function scopeAtRisk(Builder $query): Builder
    {
        return $query
            ->whereIn('tier', ['Gold', 'Platinum'])
            ->whereNotNull('churn_probability')
            ->orderByDesc('churn_probability');
    }
}
