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
    Schema::table('withdrawals', function (Blueprint $table) {
        $table->dropColumn(['fee', 'coin', 'usd_amount']);
    });
}

public function down()
{
    Schema::table('withdrawals', function (Blueprint $table) {
        $table->decimal('fee', 18, 2)->nullable();
        $table->string('coin')->nullable();
        $table->decimal('usd_amount', 18, 2)->nullable();
    });
}
};
