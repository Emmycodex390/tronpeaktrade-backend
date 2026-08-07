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
        'admin_note',
    ];  
    
    protected $casts = [
    'start_date' => 'datetime',
    'end_date' => 'datetime',
    'last_payout_at' => 'datetime',
    // Without these, Postgres/PDO returns decimal columns as strings in
    // JSON responses (e.g. "10.00" instead of 10), which crashes any
    // frontend code calling .toFixed() on them directly.
    'amount' => 'float',
    'profit_percent' => 'float',
    'expected_profit' => 'float',
    'paid_out' => 'float',
];

    public function withdrawalVerifications()
    {
        return $this->hasMany(InvestmentWithdrawalVerification::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}