<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 2025_11_21 "rebuild_withdrawals_table" migration dropped and
     * recreated withdrawals without a `coin` column, even though
     * WithdrawalController::crypto() has always tried to store one —
     * it was just silently dropped since it wasn't in $fillable either.
     * That meant crypto withdrawals never recorded which coin was
     * actually being withdrawn.
     */
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->string('coin')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropColumn('coin');
        });
    }
};
