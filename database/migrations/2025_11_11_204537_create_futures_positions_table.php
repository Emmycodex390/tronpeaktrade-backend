<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('futures_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->string('symbol'); // e.g. BTCUSDT
            $table->string('type')->default('perpetual'); // or delivery
            $table->enum('side', ['B', 'S']); // B=Buy/Long, S=Sell/Short
            $table->unsignedInteger('leverage')->default(10);

            $table->decimal('size', 20, 8)->default(0);
            $table->decimal('margin_usdt', 20, 8)->default(0);
            $table->decimal('pnl_usdt', 20, 8)->default(0);
            $table->decimal('roi', 10, 2)->default(0);
            $table->decimal('margin_ratio', 10, 2)->default(0);

            $table->decimal('entry_price', 20, 8)->default(0);
            $table->decimal('mark_price', 20, 8)->default(0);
            $table->decimal('liquidation_price', 20, 8)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('futures_positions');
    }
};