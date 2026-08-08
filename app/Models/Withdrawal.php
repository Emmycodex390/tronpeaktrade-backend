<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',

        // withdrawal type: bank | crypto
        'type',

        // CRYPTO coin symbol (BTC, ETH, SOL) — added after the column was
        // dropped by a rebuild migration that never restored it.
        'coin',

        // BANK fields
        'bank_name',
        'account_number',
        'account_name',

        // CRYPTO fields
        'address',
        'network',

        // transaction amounts
        'amount',

        // status
        'status',

        // extra message/notes
        'note',

        // admin's reason when rejecting — added alongside the admin
        // withdrawal-approval controller.
        'admin_note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifications()
    {
        return $this->hasMany(WithdrawalVerification::class);
    }
}