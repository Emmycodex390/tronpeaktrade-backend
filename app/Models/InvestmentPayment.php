<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvestmentPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_name',
        'amount',
        'profit_percent',
        'expected_profit',
        'duration',
        'status',
        'start_date',
        'end_date',
        'transaction_id',
        'selar_payment_link', // ✅ NEW FIELD
        'payment_method',
        'payment_coin',
    ];  
    
    protected $casts = [
    'start_date' => 'datetime',
    'end_date' => 'datetime',
    'last_payout_at' => 'datetime',
];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}