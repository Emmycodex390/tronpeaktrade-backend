<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('trading_mode')->default('crypto')->after('symbol'); // crypto|forex|futures
            $table->decimal('margin', 24, 8)->default(0)->after('balance');
            $table->integer('leverage')->default(1)->after('margin');
            // optional: index
            $table->index(['user_id', 'symbol', 'trading_mode']);
        });
    }

    public function down()
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'symbol', 'trading_mode']);
            $table->dropColumn(['trading_mode', 'margin', 'leverage']);
        });
    }
};