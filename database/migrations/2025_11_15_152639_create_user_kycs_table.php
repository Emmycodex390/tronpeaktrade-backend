<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
{
    Schema::create('user_kycs', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('status')->default('pending'); // pending / approved / rejected
        $table->string('id_type')->nullable();
        $table->string('id_document_front')->nullable();
        $table->string('id_document_back')->nullable();
        $table->string('selfie')->nullable();
        $table->string('face_match_score')->nullable();
        $table->string('rejection_reason')->nullable();
        $table->timestamp('verified_at')->nullable();

        $table->timestamps();

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_kycs');
    }
};
