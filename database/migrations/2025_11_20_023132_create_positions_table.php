<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('pair'); // e.g. BTC/USDT
            $table->string('side'); // buy/sell
            $table->decimal('entry_price', 24, 8);
            $table->decimal('size', 24, 8); // base asset size
            $table->decimal('margin_used', 24, 8)->default(0);
            $table->integer('leverage')->default(1);
            $table->decimal('unrealized_pnl', 24, 8)->default(0);
            $table->string('mode')->default('crypto'); // crypto|forex|futures
            $table->string('status')->default('open'); // open|closed
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'pair', 'status']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('positions');
    }
};