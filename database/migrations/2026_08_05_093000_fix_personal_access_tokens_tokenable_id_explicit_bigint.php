<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The first fix migration used morphs('tokenable'), which we assumed
     * created a normal bigint column — but AppServiceProvider had
     * Schema::defaultMorphKeyType('uuid') silently forcing it to uuid
     * anyway, so that fix didn't actually fix anything. That override
     * is now removed (see AppServiceProvider), but this table was
     * already recreated wrong by the first migration, so it needs
     * fixing again — this time with explicit column types that don't
     * depend on any global default.
     */
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tokenable_id');
            $table->string('tokenable_type');
            $table->index(['tokenable_type', 'tokenable_id']);
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
