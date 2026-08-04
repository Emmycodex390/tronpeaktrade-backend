<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvestmentPlansTable extends Migration
{
    public function up()
    {
        Schema::create('investment_plans', function (Blueprint $table) {
            $table->id();
            $table->string('plan_name')->unique();
            $table->decimal('min_amount', 12, 2)->default(0);
            $table->decimal('profit_percent', 5, 2)->default(0);
            $table->integer('duration')->comment('days');
            $table->string('selar_payment_link')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('investment_plans');
    }
}