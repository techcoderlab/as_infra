<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index(); // Multi-tenancy
            $table->string('name');
            $table->string('webhook_url'); // The n8n/Zapier URL
            $table->string('webhook_secret')->nullable(); // Optional Auth Header/Secret
            $table->string('avatar_url')->nullable(); // Optional Bot Icon
            $table->text('welcome_message')->nullable(); // Initial greeting
            $table->timestamps();

            // Foreign key constraint if you have a tenants table
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chats');
    }
};
