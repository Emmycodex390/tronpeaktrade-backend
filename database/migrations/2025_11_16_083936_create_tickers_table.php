<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('tickers', function (Blueprint $table) {
            $table->id();
            $table->string('symbol'); // e.g., BTCUSDT, EURUSD
            $table->enum('type', ['crypto','forex','futures']);
            $table->decimal('last_price', 20, 8)->default(0);
            $table->decimal('price_change_percent', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('tickers');
    }
};
