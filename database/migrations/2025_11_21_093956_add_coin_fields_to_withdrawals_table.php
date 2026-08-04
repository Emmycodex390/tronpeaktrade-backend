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
        $table->string('coin')->nullable()->after('network');
        $table->decimal('usd_amount', 18, 2)->nullable()->after('amount');
    });
}

public function down()
{
    Schema::table('withdrawals', function (Blueprint $table) {
        $table->dropColumn(['coin', 'usd_amount']);
    });
}
};
