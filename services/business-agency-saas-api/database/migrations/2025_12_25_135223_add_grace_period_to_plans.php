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
        Schema::table('plans', function (Blueprint $table) {
            $table->decimal('price', 10, 2); // 10 total digits, 2 decimal places (e.g., 99999999.99)
        });
        Schema::table('plan_tenant', function (Blueprint $table) {
            $table->integer('grace_period_days')->default(3)->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('price');
        });
        Schema::table('plan_tenant', function (Blueprint $table) {
            $table->dropColumn('grace_period_days');
        });
    }
};
