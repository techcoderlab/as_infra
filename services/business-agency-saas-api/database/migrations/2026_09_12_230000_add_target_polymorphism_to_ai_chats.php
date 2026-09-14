<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Update ai_chats
        Schema::table('ai_chats', function (Blueprint $table) {
            $table->string('target_type')->nullable()->after('tenant_id');
            $table->unsignedBigInteger('target_id')->nullable()->after('target_type');
            $table->string('platform')->default('web')->after('target_id');
            $table->string('status')->default('active')->after('platform');

            $table->index(['target_type', 'target_id'], 'idx_ai_chats_target');
            $table->index(['tenant_id', 'target_type', 'target_id'], 'idx_ai_chats_tenant_target');
            $table->index(['tenant_id', 'platform'], 'idx_ai_chats_tenant_platform');
        });

        // 2. Update chat_messages
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('platform_message_id')->nullable()->after('files');
            $table->jsonb('metadata')->nullable()->after('platform_message_id');

            $table->index(['ai_chat_id', 'id'], 'idx_chat_messages_history');
            $table->index(['ai_chat_id', 'role'], 'idx_chat_messages_role');
            $table->unique(['ai_chat_id', 'platform_message_id'], 'uniq_chat_messages_platform_msg');
        });

        // 3. Update lead_chat_sessions
        Schema::table('lead_chat_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_chat_id')->nullable()->after('id');
            
            // If ai_chats is wiped or deleted, session can remain but the link is nullified
            $table->foreign('ai_chat_id')->references('id')->on('ai_chats')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lead_chat_sessions', function (Blueprint $table) {
            $table->dropForeign(['ai_chat_id']);
            $table->dropColumn('ai_chat_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropUnique('uniq_chat_messages_platform_msg');
            $table->dropIndex('idx_chat_messages_role');
            $table->dropIndex('idx_chat_messages_history');
            
            $table->dropColumn(['metadata', 'platform_message_id']);
            
            // Note: Reverting user_id to non-nullable might fail if there are nulls.
            // SQLite/MySQL handling differs. We will just leave it nullable for safety in down(),
            // or we could force it but it's risky if we have lead messages.
            // $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('ai_chats', function (Blueprint $table) {
            $table->dropIndex('idx_ai_chats_tenant_platform');
            $table->dropIndex('idx_ai_chats_tenant_target');
            $table->dropIndex('idx_ai_chats_target');
            
            $table->dropColumn(['status', 'platform', 'target_id', 'target_type']);
        });
    }
};
