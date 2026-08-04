<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::dropIfExists('withdrawals');

    Schema::create('withdrawals', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');

        $table->enum('type', ['bank', 'crypto']);

        // BANK FIELDS
        $table->string('bank_name')->nullable();
        $table->string('account_number')->nullable();
        $table->string('account_name')->nullable();

        // CRYPTO FIELDS
        $table->string('address')->nullable();
        $table->string('network')->nullable();

        $table->decimal('amount', 18, 2);
        $table->decimal('fee', 18, 2)->default(0);

        $table->enum('status', ['pending', 'processing', 'completed', 'rejected'])
            ->default('pending');

        $table->text('note')->nullable();

        $table->timestamps();
    });
}

public function down()
{
    Schema::dropIfExists('withdrawals');
}
};
