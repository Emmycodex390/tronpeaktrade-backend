<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'id_document_front')) {
                $table->string('id_document_front')->nullable()->after('id_document');
            }

            if (!Schema::hasColumn('users', 'id_document_back')) {
                $table->string('id_document_back')->nullable()->after('id_document_front');
            }

            if (!Schema::hasColumn('users', 'face_image')) {
                $table->string('face_image')->nullable()->after('id_document_back');
            }

            if (!Schema::hasColumn('users', 'face_match_score')) {
                $table->float('face_match_score')->nullable()->after('face_image');
            }

            if (!Schema::hasColumn('users', 'id_status')) {
                $table->enum('id_status', ['pending', 'verified', 'rejected'])
                      ->default('pending')
                      ->after('face_match_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'id_document_front')) {
                $table->dropColumn('id_document_front');
            }

            if (Schema::hasColumn('users', 'id_document_back')) {
                $table->dropColumn('id_document_back');
            }

            if (Schema::hasColumn('users', 'face_image')) {
                $table->dropColumn('face_image');
            }

            if (Schema::hasColumn('users', 'face_match_score')) {
                $table->dropColumn('face_match_score');
            }

            if (Schema::hasColumn('users', 'id_status')) {
                $table->dropColumn('id_status');
            }
        });
    }
};