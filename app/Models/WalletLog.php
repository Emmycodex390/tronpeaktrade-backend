<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WalletLog extends Model
{
    protected $fillable = [
        'user_id',
        'coin',
        'amount',
        'usd_value',
        'action'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}