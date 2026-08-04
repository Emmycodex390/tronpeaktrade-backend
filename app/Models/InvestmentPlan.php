<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentPlan extends Model
{
    protected $fillable = [
        'plan_name',
        'min_amount',
        'profit_percent',
        'duration',
        'selar_payment_link',
        'status',
    ];
}