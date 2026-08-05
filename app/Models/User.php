<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'username',
        'name',
        'email',
        'password',
        'phone',
        'role',
        'status',
        'country',
        'address',
        'avatar',

        // --- KYC fields ---
        'id_type',
        'id_document',
        'id_document_front',
        'id_document_back',
        'face_image',
        'id_status',
        'face_match_score',

        // --- Payment fields ---
        'bank_name',
        'account_name',
        'account_number',
        'paypal_email',

        // --- Balances ---
        'total_usdt',
        'conversion_ngn',
        'asset_balance',
        'investment_balance',
        'ai_investment_balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'face_match_score' => 'float',
        'password' => 'hashed',
    ];

    protected $appends = ['avatar_url'];

    // Accessor
    public function getAvatarUrlAttribute()
    {
        return $this->avatar ? asset('storage/' . $this->avatar) : null;
    }

    // -------------------------
    // Relationships
    // -------------------------

    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    public function notificationPreference()
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function aiInvestments()
    {
        return $this->hasMany(AiInvestment::class, 'user_id');
    }

    public function futuresPositions()
    {
        return $this->hasMany(FuturesPosition::class, 'user_id');
    }

    public function futuresBalance()
    {
        return $this->hasOne(FuturesBalance::class, 'user_id');
    }

    public function investmentPayments()
    {
        return $this->hasMany(InvestmentPayment::class, 'user_id');
    }  
    
    public function hasCompletedAllVerifications(): bool
{
    $activeCodes = \App\Models\VerificationCode::where('active', true)->count();

    if ($activeCodes === 0) {
        // If no verification codes exist, user is NOT verified
        return false;
    }

    $submitted = \App\Models\UserVerificationEntry::where('user_id', $this->id)
        ->whereHas('verificationCode', function ($q) {
            $q->where('active', true);
        })
        ->count();

    return $submitted >= $activeCodes;
}
}