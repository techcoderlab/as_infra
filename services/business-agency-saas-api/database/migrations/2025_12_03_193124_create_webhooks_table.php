<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index(); // Assumes tenants table exists or logic handles it
            $table->string('name')->nullable();
            $table->string('url');
            $table->string('secret')->nullable();
            $table->jsonb('events')->nullable(); // ["lead.created", "lead.updated.status"]
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // If you have a tenants table with foreign keys:
            // $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
