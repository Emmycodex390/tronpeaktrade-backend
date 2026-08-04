<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $fillable = [
    'user_id',
    'pair',
    'side',
    'entry_price',
    'size',
    'margin_used',
    'leverage',
    'unrealized_pnl',
    'pnl',
    'exit_price',
    'mode',
    'status',
    'closed_at',
];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}