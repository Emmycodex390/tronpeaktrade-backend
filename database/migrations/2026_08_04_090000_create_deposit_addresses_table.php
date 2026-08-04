<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('coin');       // e.g. BTC, ETH, USDT
            $table->string('network');    // e.g. Bitcoin, ERC20, TRC20, BEP20
            $table->string('address');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['coin', 'network']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_addresses');
    }
};
