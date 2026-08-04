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
        $table->string('selar_payment_link')->nullable()->after('transaction_id');
    });
}

public function down()
{
    Schema::table('investment_payments', function (Blueprint $table) {
        $table->dropColumn('selar_payment_link');
    });
}
};
