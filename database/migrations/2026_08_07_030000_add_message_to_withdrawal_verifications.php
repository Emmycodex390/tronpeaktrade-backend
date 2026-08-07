<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_withdrawal_verifications', function (Blueprint $table) {
            if (!Schema::hasColumn('investment_withdrawal_verifications', 'message')) {
                $table->text('message')->nullable()->after('label');
            }
        });

        Schema::table('stake_withdrawal_verifications', function (Blueprint $table) {
            if (!Schema::hasColumn('stake_withdrawal_verifications', 'message')) {
                $table->text('message')->nullable()->after('label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_withdrawal_verifications', function (Blueprint $table) {
            $table->dropColumn('message');
        });

        Schema::table('stake_withdrawal_verifications', function (Blueprint $table) {
            $table->dropColumn('message');
        });
    }
};
