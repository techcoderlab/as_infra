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
        Schema::create('agent_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // The specific AI Agent to run
            $table->foreignId('ai_agent_id')->constrained()->cascadeOnDelete();

            // The Event Class Name (e.g., "App\Events\LeadCreated")
            $table->string('event_class');

            // Optional: Limit trigger to specific scenarios (simple rule engine)
            // $table->jsonb('conditions')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Index for fast lookup by tenant + event
            $table->index(['tenant_id', 'event_class']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_triggers');
    }
};
