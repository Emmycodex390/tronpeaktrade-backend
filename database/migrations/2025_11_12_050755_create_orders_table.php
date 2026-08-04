<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('pair'); // e.g. BTCUSDT
            $table->enum('side', ['buy', 'sell']);
            $table->enum('type', ['limit', 'market', 'stop']);
            $table->decimal('price', 20, 8)->nullable();
            $table->decimal('amount', 20, 8);
            $table->decimal('trigger_price', 20, 8)->nullable();
            $table->decimal('filled', 20, 8)->default(0);
            $table->enum('status', ['pending', 'filled', 'partial', 'cancelled'])->default('pending');
            $table->string('order_id')->unique(); // external or internal reference
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};