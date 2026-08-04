<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ledger extends Model
{
    // 
    
    protected $fillable = [
        'user_id',
        'type',
        'amount',
        'pnl',
        'symbol',
        'mode',
        'note',
    ];
}

