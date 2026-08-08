<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withdrawal_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('withdrawal_id')
                ->constrained('withdrawals')
                ->cascadeOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();
            $table->text('message')->nullable();

            $table->string('code', 10);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['withdrawal_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_verifications');
    }
};
