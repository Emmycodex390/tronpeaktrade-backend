<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','coin','amount','usd_amount','status'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}