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
        Schema::table('ai_jobs', function (Blueprint $table) {
            // 1. Drop the old, less efficient index
            $table->dropIndex(['target_type', 'target_id']);

            // 2. Add the robust compound index for your specific search
            $table->index(
                ['tenant_id', 'agent_slug', 'target_id', 'target_type'],
                'ai_jobs_search_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->dropIndex('ai_jobs_search_index');
            $table->index(['target_type', 'target_id']);
        });
    }
};
