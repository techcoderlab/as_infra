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
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();
            // Nullable tenant_id: NULL = Super Admin / Default Global Settings
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade');
            // $table->jsonb('client_theme')->nullable(); // Stores { "primary": "#hex", "font": "Inter" }
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
