<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * user_stakes previously only had id + timestamps — no reference to
     * which plan, how much was staked, or when, so there was nothing
     * for UserStakeController to actually operate on.
     */
    public function up(): void
    {
        Schema::table('user_stakes', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->onDelete('cascade');
            $table->foreignId('staking_plan_id')->after('user_id')->constrained()->onDelete('cascade');
            $table->string('coin')->after('staking_plan_id');
            $table->decimal('amount', 20, 8)->after('coin');
            $table->decimal('apy', 6, 2)->after('amount'); // snapshotted from the plan at subscribe time
            $table->unsignedInteger('duration_days')->after('apy'); // snapshotted too
            $table->decimal('total_claimed', 20, 8)->default(0)->after('duration_days');
            $table->timestamp('started_at')->after('total_claimed');
            $table->timestamp('ends_at')->nullable()->after('started_at'); // null = flexible, no lock
            $table->timestamp('last_claimed_at')->nullable()->after('ends_at');
            $table->enum('status', ['active', 'completed', 'cancelled'])->default('active')->after('last_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_stakes', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['staking_plan_id']);
            $table->dropColumn([
                'user_id', 'staking_plan_id', 'coin', 'amount', 'apy',
                'duration_days', 'total_claimed', 'started_at', 'ends_at',
                'last_claimed_at', 'status',
            ]);
        });
    }
};
