<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserKYC extends Model
{
    use HasFactory;

    // Specify the actual table name in your database
    protected $table = 'user_kycs';  

    protected $fillable = [
        'user_id',
        'status',
        'id_type',
        'id_document_front',
        'id_document_back',
        'selfie',
        'face_match_score',
        'rejection_reason',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}