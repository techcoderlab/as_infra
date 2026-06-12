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
        Schema::create('ai_jobs', function (Blueprint $table) {
            $table->id();

            // Foreign Key with Cascade (Auto-clean if tenant is deleted)
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // 1. POSTGRES OPTIMIZATION: Native UUID
            // Much faster and smaller (16 bytes) than string(36)
            $table->uuid('job_uuid')->unique();

            $table->string('agent_slug');

            // 2. POSTGRES OPTIMIZATION: Compound Index
            // Queries usually look for "Lead #123" (Type + ID).
            // Indexing them together is 2x faster than separate indexes.
            $table->string('target_id');
            // $table->unsignedBigInteger('target_id');
            $table->string('target_type');
            $table->index(['target_type', 'target_id']);

            // 3. Status Column (Standard Index for History/Logs)
            // We keep this standard index so you can efficiently query 'failed' or 'completed' jobs later.
            $table->string('status')->default('pending')->index();

            $table->integer('attempts')->default(0);

            // 4. POSTGRES OPTIMIZATION: JSONB (Binary JSON)
            // Allows indexing specific keys inside the JSON later if needed.
            // also faster parsing than text-based JSON.
            $table->jsonb('payload');
            $table->jsonb('result')->nullable();

            $table->text('error_message')->nullable();

            // Performance Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        /**
         * 5. SUPER OPTIMIZATION: Partial Index for Workers
         * This creates a tiny, lightning-fast index ONLY for 'pending'/'processing' jobs.
         * Your queue workers will hit this index instead of the main one,
         * making job pickups instant even if you have 10 million 'completed' rows.
         */
        DB::statement("CREATE INDEX ai_jobs_active_status_index ON ai_jobs (status) WHERE status IN ('pending', 'processing')");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
    }
};
