<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmation code scoped to a single bank/crypto withdrawal
 * request. A withdrawal can have several — admin creates them one at a
 * time. Unlike the investment/stake version this replaced, confirming
 * ALL of a withdrawal's codes auto-approves it — no separate admin
 * click needed (see WithdrawalController::verifyCode).
 */
class WithdrawalVerification extends Model
{
    protected $fillable = [
        'withdrawal_id',
        'created_by',
        'label',
        'message',
        'code',
        'sent_at',
        'verified_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function withdrawal(): BelongsTo
    {
        return $this->belongsTo(Withdrawal::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}