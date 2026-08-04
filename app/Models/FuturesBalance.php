<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuturesBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'margin_balance',
        'wallet_balance',
        'unrealized_pnl',
        'realized_pnl_today',
        'realized_pnl_percent',
    ];
}