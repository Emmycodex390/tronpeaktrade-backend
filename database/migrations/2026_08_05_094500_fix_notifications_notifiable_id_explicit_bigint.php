<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same root cause as the personal_access_tokens fix: the now-removed
     * Schema::defaultMorphKeyType('uuid') override made this table's
     * morphs('notifiable') call create notifiable_id as uuid, which
     * can't hold users.id (a normal bigint). The table's own `id`
     * column correctly stays uuid — that's Laravel's standard design
     * for the notifications table itself, unrelated to this bug.
     */
    public function up(): void
    {
        Schema::dropIfExists('notifications');

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->unsignedBigInteger('notifiable_id');
            $table->string('notifiable_type');
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
