<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserStake extends Model
{
    protected $fillable = [
        'user_id',
        'staking_plan_id',
        'coin',
        'amount',
        'apy',
        'duration_days',
        'total_claimed',
        'started_at',
        'ends_at',
        'last_claimed_at',
        'status',
        'admin_note',
    ];

    protected $casts = [
        'amount' => 'float',
        'apy' => 'float',
        'total_claimed' => 'float',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
        'last_claimed_at' => 'datetime',
    ];

    public function plan()
    {
        return $this->belongsTo(StakingPlan::class, 'staking_plan_id');
    }

    public function withdrawalVerifications()
    {
        return $this->hasMany(StakeWithdrawalVerification::class);
    }
}