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
        // 1. Manually handle the target_id type conversion for Postgres
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_jobs ALTER COLUMN target_id TYPE bigint USING target_id::bigint');
        }

        // 2. Use Schema for the rest (nullability and target_type)
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('target_id')->nullable()->change();
            $table->string('target_type')->nullable()->change();
        });
    }

    public function down(): void
    {
        // 1. Revert target_id back to string
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ai_jobs ALTER COLUMN target_id TYPE varchar USING target_id::text');
        }

        // 2. Revert nullability
        Schema::table('ai_jobs', function (Blueprint $table) {
            $table->string('target_id')->nullable(false)->change();
            $table->string('target_type')->nullable(false)->change();
        });
    }
};
