<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index(); // Link to Tenant
            $table->foreignId('user_id')->nullable()->index(); // If acting as a user
            $table->string('api_key_id')->nullable(); // Track which specific key was used
            $table->string('method'); // GET, POST, etc.
            $table->string('route'); // /api/v1/leads
            $table->integer('status_code'); // 200, 422, 500
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('payload')->nullable(); // Request body (sanitized)
            $table->float('duration_ms')->nullable(); // Performance tracking
            $table->timestamps();

            // Index for fast searching logs by tenant
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_audit_logs');
    }
};
