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
        Schema::create('integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('service'); // e.g., 'openai', 'gemini', 'whatsapp'
            $table->string('key');     // A unique identifier for the specific config (e.g. 'default')
            $table->text('value');     // The encrypted JSON payload
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure a tenant can't have duplicate keys for the same service
            $table->unique(['tenant_id', 'service', 'key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
