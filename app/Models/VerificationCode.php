<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VerificationCode extends Model
{
    // allow mass assignment for these admin-editable fields
    protected $fillable = [
        'name',          // internal name/label (optional)
        'header',        // short title shown to users
        'description',   // longer description/instructions
        'code',          // the actual 6-digit code (string to preserve leading zeros)
        'active',        // boolean
    ];

    protected $casts = [
        'active' => 'boolean',
        'code'   => 'string', // keep as string to preserve leading zeroes
    ];

    public function userEntries(): HasMany
    {
        return $this->hasMany(UserVerificationEntry::class);
    }
}