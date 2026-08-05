<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The live personal_access_tokens table (seeded via an earlier raw
     * SQL dump, not this migration) has tokenable_id as uuid — doesn't
     * match users.id, which is a normal auto-incrementing bigint. No
     * real tokens exist yet (token auth is brand new), so it's safe to
     * just drop and recreate correctly rather than trying to cast
     * existing data.
     */
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
