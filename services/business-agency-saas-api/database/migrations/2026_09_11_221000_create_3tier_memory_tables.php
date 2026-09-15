<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // 1. Enable pgvector
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');

        // 2. Create episodic_facts table
        Schema::create('episodic_facts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('fact_type')->index();
            $table->text('fact_value');
            $table->float('confidence')->default(1.0);
            $table->string('source_turn_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('lead_id')->references('id')->on('leads')->onDelete('cascade');
        });

        // Add partial unique index for episodic_facts
        DB::statement('CREATE UNIQUE INDEX episodic_facts_unique_active ON episodic_facts (tenant_id, lead_id, fact_type) WHERE superseded_at IS NULL;');

        // 3. Create chat_summaries table
        Schema::create('chat_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->text('summary_text');
            $table->integer('covers_up_to_turn');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_summaries');
        Schema::dropIfExists('episodic_facts');
        DB::statement('DROP EXTENSION IF EXISTS vector;');
    }
};
