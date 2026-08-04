<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositAddress extends Model
{
    protected $fillable = ['coin', 'network', 'address', 'active'];
}
