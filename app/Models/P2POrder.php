<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class P2POrder extends Model
{
    protected $table = 'p2p_orders';

    protected $fillable = [
        'user_id',
        'vendor_id',
        'type',
        'asset',
        'amount',
        'status',
    ];

    public function vendor()
    {
        return $this->belongsTo(P2PVendor::class, 'vendor_id');
    }
}
