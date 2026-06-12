<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('lead_chat_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Core Relationships
            $table->unsignedBigInteger('lead_id')->index();
            $table->unsignedBigInteger('tenant_id')->index();

            // Platform Agnostic Identifiers
            $table->string('platform')->index(); // 'whatsapp', 'messenger', 'telegram', 'web'
            $table->string('platform_user_id')->index(); // The phone number, PSID, or Chat ID

            // Conversation State
            $table->string('status')->default('active'); // active, paused, closed, expired
            $table->dateTime('last_interaction_at');

            // AI Memory & Context
            $table->longText('thread_id')->nullable(); // OpenAI Thread ID
            $table->jsonb('context_data')->nullable(); // Store extracted entities (e.g. {"name": "Mark", "bill": 250})
            $table->integer('message_count')->default(0); // Track depth of conversation

            $table->timestamps();

            // Constraints
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
            // Ensure one active session per platform user per tenant
            $table->unique(['tenant_id', 'platform', 'platform_user_id'], 'unique_active_session');
        });
    }

    public function down()
    {
        Schema::dropIfExists('lead_chat_sessions');
    }
};
