<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FuturesPosition extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'symbol',
        'type',
        'side',
        'leverage',
        'size',
        'margin_usdt',
        'pnl_usdt',
        'roi',
        'margin_ratio',
        'entry_price',
        'mark_price',
        'liquidation_price',
    ];
}