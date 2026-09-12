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
        Schema::create('tenant_knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('title');
            $table->enum('source_type', ['document', 'url', 'manual_note'])->default('document');
            $table->text('source_url')->nullable();
            $table->text('content')->nullable();
            $table->boolean('is_active')->default(true);
            $table->enum('status', ['pending', 'processing', 'indexed', 'failed'])->default('pending');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_knowledge_sources');
    }
};
