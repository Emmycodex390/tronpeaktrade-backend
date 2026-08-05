<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('investment_payments', 'admin_note')) {
                $table->text('admin_note')->nullable()->after('payment_coin');
            }
        });

        Schema::table('user_stakes', function (Blueprint $table) {
            if (!Schema::hasColumn('user_stakes', 'admin_note')) {
                $table->text('admin_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('investment_payments', function (Blueprint $table) {
            $table->dropColumn('admin_note');
        });
        Schema::table('user_stakes', function (Blueprint $table) {
            $table->dropColumn('admin_note');
        });
    }
};
