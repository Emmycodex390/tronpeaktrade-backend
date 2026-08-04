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
}