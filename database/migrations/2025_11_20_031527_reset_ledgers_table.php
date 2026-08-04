<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Drop if already exists
        Schema::dropIfExists('ledgers');

        // Recreate fresh table
        Schema::create('ledgers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id');

            // deposit, withdrawal, trade-open, trade-close, close-position
            $table->string('type');

            // amount credited/debited or margin used
            $table->decimal('amount', 20, 8)->default(0);

            // profit or loss
            $table->decimal('pnl', 20, 8)->nullable();

            // USDT, USD, BTC, etc.
            $table->string('symbol')->nullable();

            // crypto | forex | futures
            $table->string('mode')->nullable();

            // note or explanation
            $table->string('note')->nullable();

            $table->timestamps();

            // foreign key
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ledgers');
    }
};