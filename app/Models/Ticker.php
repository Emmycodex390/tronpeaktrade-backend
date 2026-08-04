<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ticker extends Model {
    use HasFactory;

    protected $fillable = ['symbol','type','last_price','price_change_percent'];
}