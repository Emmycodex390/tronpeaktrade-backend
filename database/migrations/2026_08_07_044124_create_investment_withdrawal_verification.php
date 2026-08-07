<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_withdrawal_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_payment_id')
                ->constrained('investment_payments')
                ->cascadeOnDelete();

            // Which admin created this requirement, and why — 'label' is
            // shown in the admin panel so a second/third verification
            // added later (e.g. "suspected compromise") is distinguishable
            // from the standard one, not just an unlabeled duplicate.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('label')->nullable();

            $table->string('code', 10);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index(['investment_payment_id', 'verified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_withdrawal_verifications');
    }
};