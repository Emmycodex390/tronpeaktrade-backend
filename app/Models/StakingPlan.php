<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StakingPlan extends Model
{
    protected $fillable = [
        'name',
        'coin',
        'apy',
        'duration_days',
        'min_amount',
        'max_amount',
        'description',
        'active',
    ];

    protected $casts = [
        'apy' => 'float',
        'min_amount' => 'float',
        'max_amount' => 'float',
        'active' => 'boolean',
    ];
}