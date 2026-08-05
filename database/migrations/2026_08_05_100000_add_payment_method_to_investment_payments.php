<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('investment_payments', 'payment_method')) {
                $table->string('payment_method')->default('selar')->after('transaction_id');
            }
            if (!Schema::hasColumn('investment_payments', 'payment_coin')) {
                $table->string('payment_coin')->nullable()->after('payment_method');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_payments', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_coin']);
        });
    }
};
