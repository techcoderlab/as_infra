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
        Schema::table('tenant_vector_memories', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'agent_id']);
            $table->dropColumn('agent_id');
            
            $table->unsignedBigInteger('source_id')->nullable()->after('tenant_id');
            $table->foreign('source_id')->references('id')->on('tenant_knowledge_sources')->onDelete('cascade');
            
            $table->index(['tenant_id', 'source_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenant_vector_memories', function (Blueprint $table) {
            $table->dropForeign(['source_id']);
            $table->dropIndex(['tenant_id', 'source_id']);
            $table->dropColumn('source_id');
            
            $table->unsignedBigInteger('agent_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'agent_id']);
        });
    }
};
