<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChurnPrediction extends Model
{
    protected $fillable = ['member_id', 'churn_probability', 'scored_at'];

    protected $casts = [
        'churn_probability' => 'float',
        'scored_at'         => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(LoyaltyMember::class, 'member_id');
    }
}
