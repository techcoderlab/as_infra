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
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // It's safe to remove tenant_id foreign key if it exists, before dropping the column.
        // The constraint name is users_tenant_id_foreign. Laravel's default.
        if (Schema::hasColumn('users', 'tenant_id')) {
            Schema::table('users', function (Blueprint $table) {
                // The original migration did not have a foreign key, only an index.
                // Dropping the column will automatically remove the index.
                $table->dropColumn('tenant_id');
            });
        }

        if (Schema::hasColumn('tenants', 'enabled_modules')) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->dropColumn('enabled_modules');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('set null');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->jsonb('enabled_modules')->nullable();
        });
    }
};
