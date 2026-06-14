<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('external_api_keys', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // $table->foreignId('tenant_id')->constrained()->onDelete('cascade');

            // The public identifier the user sends in the header
            $table->string('app_id', 32)->unique();

            // The secret used to run the HMAC math
            $table->text('secret');

            $table->string('for', 50)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('external_api_keys');
    }
};
