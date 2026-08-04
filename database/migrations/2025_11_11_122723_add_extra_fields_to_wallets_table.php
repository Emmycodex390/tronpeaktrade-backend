<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('symbol')->nullable()->after('name');
            $table->string('network')->nullable()->after('symbol');
            $table->decimal('usd_value', 20, 2)->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['name', 'symbol', 'network', 'usd_value']);
        });
    }
};