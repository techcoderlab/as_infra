<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();

            // Multi-tenancy
            $table->foreignId('tenant_id')
                ->constrained()
                ->cascadeOnDelete();

            // Identifiers
            $table->string('name');
            $table->string('slug'); // Validated unique per tenant below

            // Configuration
            $table->string('brain')
                ->comment('Service provider key, e.g., "openai", "anthropic"');
            $table->string('model')
                ->comment('Specific model identifier, e.g., "gpt-4o"');

            // Prompts
            $table->longText('system_prompt')
                ->nullable();
            $table->longText('user_prompt')
                ->nullable()
                ->comment('Supports placeholders like {{variable}}');

            // Capabilities
            $table->jsonb('tools')
                ->nullable();

            $table->boolean('is_active')
                ->default(true);

            $table->timestamps();

            // Ensure slug is unique *per tenant*
            $table->unique(['tenant_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agents');
    }
};
