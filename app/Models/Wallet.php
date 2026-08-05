<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wallet extends Model
{
    protected $fillable = [
        'user_id',
        'coin',
        'name',        // Full name of the coin e.g. Bitcoin
        'symbol',      // Short symbol e.g. BTC, ETH
        'trading_mode', // crypto | forex | futures
        'network',     // Blockchain network e.g. Bitcoin, Ethereum, BSC
        'address',     // Wallet address
        'xpub',        // Optional: for HD wallets
        'balance',     // Current balance in coin
        'usd_value',   // USD value of the wallet
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Without this, Postgres/PDO returns these decimal columns as
    // strings in JSON responses — harmless for code that just
    // interpolates them into a string, but breaks anything that calls
    // a Number method (.toFixed(), arithmetic) directly on the value.
    protected $casts = [
        'balance' => 'float',
        'usd_value' => 'float',
        'margin' => 'float',
    ];
}