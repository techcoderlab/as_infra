<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SystemSyncSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Module Sync from config
            $this->command->info('Syncing modules...');
            $modules = config('modules.metadata', []);
            foreach ($modules as $moduleData) {
                $slug = array_keys($moduleData)[0];
                Module::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => $moduleData[$slug]['label'] ?? '']
                );
            }
            $this->command->info('Modules synced.');

            // 2. Create "Standard Agency" Plan and attach all modules
            $this->command->info('Creating Standard Agency plan...');
            $plan = Plan::updateOrCreate(
                ['slug' => 'standard-agency'],
                ['name' => 'Standard Agency']
            );
            $allModuleIds = Module::pluck('id');
            $plan->modules()->sync($allModuleIds);
            $this->command->info('Standard Agency plan created and modules attached.');

            // 3. Migrate all tenants to the "Standard Agency" Plan
            $this->command->info('Assigning tenants to the Standard Agency plan...');
            Tenant::chunk(100, function ($tenants) use ($plan) {
                foreach ($tenants as $tenant) {
                    $tenant->plans()->sync([$plan->id]);
                }
            });
            $this->command->info('Tenants assigned.');

            // 4. Sync User-Tenant pivot data
            $this->command->info('Syncing user-tenant relationships...');
            if (Schema::hasColumn('users', 'tenant_id')) {
                User::whereNotNull('tenant_id')->chunk(100, function ($users) {
                    foreach ($users as $user) {
                        // Create record in tenant_user pivot
                        DB::table('tenant_user')->updateOrInsert(
                            ['user_id' => $user->id, 'tenant_id' => $user->tenant_id],
                            [
                                'role' => 'agency_owner', // As requested
                                'is_primary' => true,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]
                        );

                        // Update current_tenant_id on the user itself
                        $user->current_tenant_id = $user->tenant_id;
                        $user->save();
                    }
                });
                $this->command->info('User-tenant relationships synced.');
            } else {
                $this->command->warn('`users`.`tenant_id` column not found. Skipping user-tenant sync.');
            }
        });

        $this->command->info('System Sync Seeder completed successfully!');
    }
}
