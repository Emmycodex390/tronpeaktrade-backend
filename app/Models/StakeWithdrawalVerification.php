<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One withdrawal-confirmation code, scoped to a single matured stake's
 * final payout (reward + returned principal together). Mirrors
 * InvestmentWithdrawalVerification exactly, kept as a separate table
 * rather than a shared polymorphic one so the already-shipped
 * investment flow isn't touched by this addition.
 */
class StakeWithdrawalVerification extends Model
{
    protected $fillable = [
        'user_stake_id',
        'created_by',
        'label',
        'code',
        'sent_at',
        'verified_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function stake(): BelongsTo
    {
        return $this->belongsTo(UserStake::class, 'user_stake_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
