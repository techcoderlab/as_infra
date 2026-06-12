<?php

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Defer foreign key checks to prevent issues with table order
        // DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET CONSTRAINTS ALL DEFERRED;');
        }

        // Migrate users' tenant_id to tenant_user table
        $users = User::whereNotNull('tenant_id')->get();
        foreach ($users as $user) {
            DB::table('tenant_user')->insert([
                'user_id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'role' => $user->getRoleNames()->first(), // Assign existing role
                'is_primary' => true, // Mark as primary tenant for the user
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Set user's current_tenant_id
            $user->current_tenant_id = $user->tenant_id;
            $user->save();
        }

        // Migrate tenants' enabled_modules to new module/plan structure
        $tenants = Tenant::whereNotNull('enabled_modules')->get();
        $legacyPlanId = null;

        if ($tenants->isNotEmpty()) {
            // Create a default "Legacy Plan" for existing tenants
            $legacyPlanId = DB::table('plans')->insertGetId([
                'name' => 'Legacy Plan',
                'slug' => 'legacy-plan',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($tenants as $tenant) {
            if (is_array($tenant->enabled_modules)) {
                foreach ($tenant->enabled_modules as $moduleSlug) {
                    // Create module if it doesn't exist
                    $module = DB::table('modules')->where('slug', $moduleSlug)->first();
                    if (! $module) {
                        $moduleId = DB::table('modules')->insertGetId([
                            'name' => ucfirst(str_replace('_', ' ', $moduleSlug)),
                            'slug' => $moduleSlug,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $moduleId = $module->id;
                    }

                    // Attach module to the Legacy Plan
                    DB::table('module_plan')->updateOrInsert(
                        ['module_id' => $moduleId, 'plan_id' => $legacyPlanId]
                    );
                }
            }

            // Assign the Legacy Plan to the tenant
            if ($legacyPlanId) {
                DB::table('plan_tenant')->insert([
                    'plan_id' => $legacyPlanId,
                    'tenant_id' => $tenant->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // DB::statement('SET FOREIGN_KEY_CHECKS=1;'); for mysql its needed
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('TRUNCATE TABLE tenant_user, plan_tenant, module_plan, plans, modules CASCADE;');
        } else {
            DB::table('tenant_user')->truncate();
            DB::table('plan_tenant')->truncate();
            DB::table('module_plan')->truncate();
            DB::table('plans')->truncate();
            DB::table('modules')->truncate();
        }

        // Reset current_tenant_id for users
        User::query()->update(['current_tenant_id' => null]);
    }
};
