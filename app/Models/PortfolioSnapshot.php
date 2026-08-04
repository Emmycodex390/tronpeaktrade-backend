<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PortfolioSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'total_usd',
        'created_at',
    ];

    protected $casts = [
        'total_usd' => 'float',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
