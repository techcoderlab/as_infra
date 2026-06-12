<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Optimize ai_chats & Link to ai_agents
        Schema::table('ai_chats', function (Blueprint $table) {
            // OPTIMIZATION: string -> text for long webhook URLs with signed tokens
            $table->text('webhook_url')->change();

            // NEW RELATION: Link chat to a specific Agent personality
            $table->foreignId('ai_agent_id')
                ->nullable()
                ->after('tenant_id')
                ->constrained()
                ->nullOnDelete(); // Keeps chat history if the agent is deleted

            // Speed up multi-tenant lookups
            // Explicitly ensure tenant_id has a high-performance index
            // Note: If 'tenant_id_index' already exists, Laravel will ignore this.
            if (! $this->indexExists('ai_chats', 'ai_chats_tenant_id_index')) {
                $table->index('tenant_id');
            }
        });

        // 2. Optimize ai_agents
        Schema::table('ai_agents', function (Blueprint $table) {
            // Speed up dashboard filters and agent routing
            $table->index('is_active');
            $table->index('brain');
            $table->index('handler_class');

            $table->integer('context_window_size', false, true)->default(10);
        });

        // 3. Optimize agent_triggers
        Schema::table('agent_triggers', function (Blueprint $table) {
            // Enable the conditions field for the Rule Engine
            $table->jsonb('conditions')->nullable()->after('event_class');

            // Add index to is_active for faster status filtering
            $table->index('is_active');

            // OPTIMIZATION: Drop the old index and create a high-performance composite one
            // We put event_class FIRST because the system filters by Event type before Tenant
            $table->dropIndex(['tenant_id', 'event_class']);
            $table->index(['event_class', 'tenant_id', 'is_active'], 'idx_trigger_lookup');
        });

        // 4. Postgres Specific GIN Index for JSONB tools
        if (config('database.default') === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS ai_agents_tools_gin ON ai_agents USING GIN (tools)');
        }
    }

    public function down(): void
    {

        Schema::table('ai_chats', function (Blueprint $table) {
            $table->dropForeign(['ai_agent_id']);
            $table->dropColumn('ai_agent_id');
            $table->string('webhook_url', 255)->change();
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('ai_agents', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'brain', 'handler_class']);
            $table->dropColumn('context_window_size');
        });

        Schema::table('agent_triggers', function (Blueprint $table) {
            $table->dropIndex('idx_trigger_lookup');
            $table->dropIndex(['is_active']);
            $table->dropColumn('conditions');
            $table->index(['tenant_id', 'event_class']);
        });

        if (config('database.default') === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ai_agents_tools_gin');
        }
    }

    public function indexExists($table, $index)
    {
        // For Laravel 11 and 12
        return Schema::hasIndex($table, $index);
    }
};
