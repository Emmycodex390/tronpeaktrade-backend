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
         * Optional: Force older MySQL/MariaDB engines to use InnoDB
         * (Some shared hosts default to MyISAM which breaks foreign keys)
         */
        Schema::defaultMorphKeyType('uuid'); // Optional: If you're using UUIDs.

        /**
         * Custom password reset URL
         */
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url')
                . "/password-reset/$token?email={$notifiable->getEmailForPasswordReset()}";
        });
    }
}