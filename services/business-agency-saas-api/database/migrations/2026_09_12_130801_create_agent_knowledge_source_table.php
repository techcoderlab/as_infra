<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agent_knowledge_source', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_agent_id');
            $table->unsignedBigInteger('knowledge_source_id');
            
            $table->foreign('ai_agent_id')->references('id')->on('ai_agents')->onDelete('cascade');
            $table->foreign('knowledge_source_id')->references('id')->on('tenant_knowledge_sources')->onDelete('cascade');
            
            $table->primary(['ai_agent_id', 'knowledge_source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_knowledge_source');
    }
};
