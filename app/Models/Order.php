<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pair',
        'side',
        'type',
        'price',
        'amount',
        'trigger_price',
        'filled',
        'status',
        'order_id',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}