<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_vector_memories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->text('chunk_text');
            $table->string('source_type', 50)->default('document');
            $table->string('source_ref', 255)->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'agent_id']);
        });

        // pgvector column — Laravel's Blueprint has no native vector type
        DB::statement('ALTER TABLE tenant_vector_memories ADD COLUMN embedding vector(384);');

        // HNSW index for fast approximate nearest-neighbor search
        // cosine ops matches the <=> operator used in queries
        DB::statement('CREATE INDEX tenant_vector_memories_embedding_idx ON tenant_vector_memories USING hnsw (embedding vector_cosine_ops);');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_vector_memories');
    }
};
