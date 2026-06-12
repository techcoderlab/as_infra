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
        // Add 'limit' to the module_plan pivot table
        if (Schema::hasTable('module_plan')) {
            Schema::table('module_plan', function (Blueprint $table) {
                if (! Schema::hasColumn('module_plan', 'limit')) {
                    // -1 = Unlimited, 0 = Disabled, >0 = Specific Count
                    $table->integer('limit')->default(-1)->after('module_id');
                }
            });
        }

        // Add 'expires_at' to the plan_tenant pivot table
        if (Schema::hasTable('plan_tenant')) {
            Schema::table('plan_tenant', function (Blueprint $table) {
                if (! Schema::hasColumn('plan_tenant', 'expires_at')) {
                    $table->timestamp('expires_at')->nullable()->after('plan_id');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('module_plan')) {
            Schema::table('module_plan', function (Blueprint $table) {
                $table->dropColumn('limit');
            });
        }

        if (Schema::hasTable('plan_tenant')) {
            Schema::table('plan_tenant', function (Blueprint $table) {
                $table->dropColumn('expires_at');
            });
        }
    }
};
