<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('plan_name')->default('AI Smart Plan');
            $table->decimal('amount', 15, 2);
            $table->decimal('daily_return', 8, 2)->default(0); // %
            $table->integer('duration_days'); // e.g. 7, 30, etc.
            $table->decimal('expected_profit', 15, 2)->default(0);
            $table->decimal('earned_profit', 15, 2)->default(0);
            $table->enum('status', ['pending', 'running', 'completed', 'cancelled'])->default('pending');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();
            $table->string('transaction_id')->unique()->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_investments');
    }
};