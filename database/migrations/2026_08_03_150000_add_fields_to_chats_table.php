<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chats previously only had id + timestamps. Each row here is one
     * message; `user_id` identifies which customer's support thread it
     * belongs to (so /api/chat/{chatId}/messages treats chatId as the
     * customer's user id — a simple one-thread-per-user support chat,
     * not a multi-conversation system).
     */
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('user_id')->after('id')->constrained()->onDelete('cascade');
            $table->enum('sender', ['user', 'admin'])->after('user_id');
            $table->text('message')->after('sender');
            $table->timestamp('read_at')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['user_id', 'sender', 'message', 'read_at']);
        });
    }
};
