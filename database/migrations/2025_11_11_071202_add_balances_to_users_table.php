<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('total_usdt', 18, 8)->default(0);
            $table->decimal('conversion_ngn', 18, 2)->default(0);
            $table->decimal('asset_balance', 18, 8)->default(0);
            $table->decimal('investment_balance', 18, 8)->default(0);
            $table->decimal('ai_investment_balance', 18, 8)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'total_usdt',
                'conversion_ngn',
                'asset_balance',
                'investment_balance',
                'ai_investment_balance',
            ]);
        });
    }
};