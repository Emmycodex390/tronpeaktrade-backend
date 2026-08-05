<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

// auth.php routes removed — login/register/logout/password-reset/email-
// verification now live in routes/api.php as token-based (Bearer token
// via Sanctum), not session/cookie-based. See api.php for why.
