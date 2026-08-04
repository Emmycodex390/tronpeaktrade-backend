<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
{
    Schema::create('p2_p_vendors', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->string('avatar')->nullable();
        $table->string('currency', 10);
        $table->decimal('price', 15, 2);
        $table->decimal('min_limit', 15, 2);
        $table->decimal('max_limit', 15, 2);
        $table->json('payment_methods');
        $table->decimal('quantity', 15, 2);
        $table->integer('trades')->default(0);
        $table->decimal('completion', 5, 2)->default(0);
        $table->boolean('verified')->default(false);
        $table->boolean('online')->default(false);
        $table->timestamps();
    });
}
};
