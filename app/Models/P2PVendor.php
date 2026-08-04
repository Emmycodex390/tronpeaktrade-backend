<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class P2PVendor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'avatar',
        'currency',
        'price',
        'min_limit',
        'max_limit',
        'payment_methods', // JSON array
        'quantity',
        'trades',
        'completion',
        'verified',
        'online',
    ];

    protected $casts = [
        'payment_methods' => 'array',
        'verified' => 'boolean',
        'online' => 'boolean',
    ];
}