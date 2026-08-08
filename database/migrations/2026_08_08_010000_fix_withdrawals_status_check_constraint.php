<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The withdrawals table has a Postgres CHECK constraint on `status`
 * that was never updated as new statuses were introduced — it was
 * rejecting 'approved' and 'cancelled' outright at the database level,
 * regardless of what application code tried to set. This drops and
 * recreates it with every status the app actually uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT IF EXISTS withdrawals_status_check');
        DB::statement("ALTER TABLE withdrawals ADD CONSTRAINT withdrawals_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT IF EXISTS withdrawals_status_check');
        DB::statement("ALTER TABLE withdrawals ADD CONSTRAINT withdrawals_status_check CHECK (status IN ('pending', 'approved', 'rejected'))");
    }
};