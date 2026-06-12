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
        // Create tenant_user pivot table
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('role')->nullable(); // For tenant-specific roles
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
        });

        // Update users table
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_tenant_id')->nullable()->constrained('tenants')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_tenant_id']);
            $table->dropColumn('current_tenant_id');
        });

        Schema::dropIfExists('tenant_user');
    }
};
