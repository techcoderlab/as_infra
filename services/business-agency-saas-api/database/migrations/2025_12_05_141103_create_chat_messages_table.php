<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ai_chat_id');
            $table->unsignedBigInteger('user_id'); // Privacy: Link to specific user
            $table->string('role'); // 'user' or 'ai'
            $table->longText('content')->nullable(); // The text message
            $table->jsonb('files')->nullable(); // Store file paths/metadata
            $table->timestamps();

            $table->foreign('ai_chat_id')->references('id')->on('ai_chats')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            // Index for fast history retrieval
            $table->index(['ai_chat_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
