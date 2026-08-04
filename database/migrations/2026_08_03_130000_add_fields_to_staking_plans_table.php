<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * staking_plans previously only had id + timestamps — no name, rate,
     * duration, or amount limits at all, so there was nothing for
     * StakingPlanController to actually return.
     */
    public function up(): void
    {
        Schema::table('staking_plans', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->string('coin')->default('USDT')->after('name');
            $table->decimal('apy', 6, 2)->after('coin'); // e.g. 12.50 = 12.5%
            $table->unsignedInteger('duration_days')->after('apy');
            $table->decimal('min_amount', 20, 2)->default(10)->after('duration_days');
            $table->decimal('max_amount', 20, 2)->nullable()->after('min_amount');
            $table->text('description')->nullable()->after('max_amount');
            $table->boolean('active')->default(true)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('staking_plans', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'coin', 'apy', 'duration_days',
                'min_amount', 'max_amount', 'description', 'active',
            ]);
        });
    }
};
