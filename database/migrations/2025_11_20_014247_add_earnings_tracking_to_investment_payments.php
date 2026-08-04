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
    Schema::table('investment_payments', function (Blueprint $table) {
        $table->decimal('paid_out', 18, 8)->default(0);
        $table->timestamp('last_payout_at')->nullable();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('investment_payments', function (Blueprint $table) {
            //
        });
    }
};
