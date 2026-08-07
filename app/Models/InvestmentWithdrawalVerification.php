<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One withdrawal-confirmation code, scoped to a single matured
 * investment payout. An investment can have several of these — created
 * one at a time by an admin, each independently required. Deliberately
 * separate from VerificationCode (the unrelated account-wide gate) and
 * SubscriptionCode (plan-redemption codes).
 */
class InvestmentWithdrawalVerification extends Model
{
    protected $fillable = [
        'investment_payment_id',
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

    public function investment(): BelongsTo
    {
        return $this->belongsTo(InvestmentPayment::class, 'investment_payment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}