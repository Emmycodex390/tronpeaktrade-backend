<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Drop table if exists
        Schema::dropIfExists('ai_investments');

        // Recreate with correct structure
        Schema::create('ai_investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pair');
            $table->enum('type', ['market','limit','stop']);
            $table->enum('side', ['buy','sell']);
            $table->decimal('amount', 20, 2);
            $table->integer('duration_days')->default(7);
            $table->decimal('expected_return', 20, 2)->nullable();
            $table->enum('status', ['active','completed','failed'])->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_investments');
    }
};