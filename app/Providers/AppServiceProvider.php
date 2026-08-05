<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * -----------------------------------------------------------
         * GLOBAL FIX for "Key too long" migration errors.
         * This forces ALL VARCHAR/STRING columns to use a safe length.
         * -----------------------------------------------------------
         */
        Schema::defaultStringLength(191);

        /**
         * defaultMorphKeyType('uuid') removed — it was silently forcing
         * every morphs() column (like personal_access_tokens.tokenable_id)
         * to be a uuid column app-wide, even though no model here
         * actually uses UUID primary keys (users.id and everything else
         * is a normal bigint). That mismatch is what broke Sanctum token
         * creation — inserting a real integer user id into a uuid
         * column. Nothing in this app currently needs UUID morphs, so
         * removing the override entirely rather than special-casing
         * around it everywhere it'd bite next.
         */

        /**
         * Custom password reset URL
         */
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')
                . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}