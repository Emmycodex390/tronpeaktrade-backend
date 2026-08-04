<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This migration is superseded by
     * 2025_11_15_152639_create_user_kycs_table.php, which creates the same
     * "user_kycs" table with the actual schema used by UserKYC.php and
     * KycController.php (id_type, id_document_front/back, selfie,
     * face_match_score, etc). Both migrations created the same table name,
     * which fails on a fresh Postgres install ("relation already exists").
     * Kept as a no-op instead of deleted so migration history stays intact
     * for any environment that already ran it.
     */
    public function up(): void
    {
        // Intentionally empty — see note above.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally empty — see note above.
    }
};
