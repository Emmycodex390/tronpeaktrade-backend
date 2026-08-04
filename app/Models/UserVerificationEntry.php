<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserVerificationEntry extends Model
{
    protected $fillable = [
        'user_id',
        'verification_code_id',
        'value',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verificationCode(): BelongsTo
    {
        return $this->belongsTo(VerificationCode::class);
    }
}