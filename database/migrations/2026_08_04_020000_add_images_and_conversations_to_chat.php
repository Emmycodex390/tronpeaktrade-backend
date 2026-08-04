<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('message');
        });

        // Tracks conversation-level state (resolved/open) separately from
        // individual messages, and gives the admin inbox something to
        // list without scanning every message row for distinct users.
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
