<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ApplicationSatrtUpSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // ---------------------------------------------------------
        // 1. Create Super Admin
        // ---------------------------------------------------------
        $superAdmin = User::firstOrCreate(
            ['email' => 'admin@saas.com'],
            [
                'name' => 'Super Admin',
                'password' => bcrypt('abc@1234'), // Change this in production
                'role' => 'super_admin',
                'current_tenant_id' => null,
            ]
        );
        $superAdmin->assignRole('super_admin');

        // // ---------------------------------------------------------
        // // 2. Global Theme Settings (Fallback)
        // // ---------------------------------------------------------
        // if (class_exists(TenantSetting::class)) {
        //     TenantSetting::updateOrCreate(
        //         ['tenant_id' => null],
        //         [
        //             'client_theme' => [
        //                 'primary' => '#0096FF', // Default Neon Blue
        //                 'secondary' => '#1e293b',
        //                 'font' => 'Inter',
        //             ]
        //         ]
        //     );
        // }

        // ---------------------------------------------------------
        // 4. Create a Demo Tenant (Agency)
        // ---------------------------------------------------------
        $tenant = Tenant::firstOrCreate(
            ['domain' => 'demo.saas.local'],
            [
                'name' => 'Demo Agency',
                'status' => 'active',
            ]
        );

        // // ---------------------------------------------------------
        // // 5. Tenant Specific Theme (Overrides Global)
        // // ---------------------------------------------------------
        if (class_exists(TenantSetting::class)) {
            TenantSetting::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'ai_provider_default' => 'openai',
                ]
            );
        }

        // ---------------------------------------------------------
        // 2. Module Sync from config
        // ---------------------------------------------------------

        $this->command->info('Syncing modules...');
        $modules = config('modules.metadata', []);
        // $this->command->info(implode(', ', array_keys($modules)));

        foreach ($modules as $key => $moduleData) {
            Module::updateOrCreate(
                ['slug' => $key],
                [
                    'name' => $moduleData['label'],
                    'route' => $moduleData['route'] ?? null,
                    'icon' => $moduleData['icon'] ?? null,
                ]
            );
        }
        $this->command->info('Modules synced.');

        // ---------------------------------------------------------
        // 3. Create "Standard Agency" Plan and attach all modules
        // ---------------------------------------------------------

        $this->command->info('Creating Standard Agency plan...');
        $plan = Plan::updateOrCreate(
            ['slug' => 'standard-agency'],
            ['name' => 'Standard Agency', 'ai_credit_limit' => 10000, 'price' => 1000]
        );
        $allModuleIds = Module::pluck('id');
        $plan->modules()->sync($allModuleIds);
        $this->command->info('Standard Agency plan created and modules attached.');

        // Migrate all tenants to the "Standard Agency" Plan
        $this->command->info('Assigning tenants to the Standard Agency plan...');
        Tenant::chunk(100, function ($tenants) use ($plan) {
            foreach ($tenants as $tenant) {
                $tenant->plans()->sync([$plan->id]);
            }
        });
        $this->command->info('Tenants assigned.');

        // Sync User-Tenant pivot data
        $this->command->info('Syncing user-tenant relationships...');
        if (Schema::hasColumn('users', 'current_tenant_id')) {
            User::whereNotNull('current_tenant_id')->chunk(100, function ($users) {
                foreach ($users as $user) {
                    // Create record in tenant_user pivot
                    DB::table('tenant_user')->updateOrInsert(
                        ['user_id' => $user->id, 'tenant_id' => $user->current_tenant_id],
                        [
                            'role' => 'agency_owner', // As requested
                            'is_primary' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    // Update current_tenant_id on the user itself
                    $user->save();
                }
            });
            $this->command->info('User-tenant relationships synced.');
        } else {
            $this->command->warn('`users`.`tenant_id` column not found. Skipping user-tenant sync.');
        }

        // ---------------------------------------------------------
        // 4. Create Agency Owner
        // ---------------------------------------------------------
        $owner = User::firstOrCreate(
            ['email' => 'owner@demo.com'],
            [
                'name' => 'John Agency',
                'password' => bcrypt('abc@1234'),
                'role' => 'agency_owner',
                'current_tenant_id' => $tenant->id,
            ]
        );
        $owner->assignRole('agency_owner');

        // ---------------------------------------------------------
        // 6. Create Agency Staff
        // ---------------------------------------------------------
        $staff = User::firstOrCreate(
            ['email' => 'staff@demo.com'],
            [
                'name' => 'Jane Staff',
                'password' => bcrypt('abc@1234'),
                'role' => 'staff',
                'current_tenant_id' => $tenant->id,
            ]
        );
        $staff->assignRole('staff');

        // Log Output
        $this->command->info('------------------------------------------');
        $this->command->info('✅ Database Seeding Complete!');
        $this->command->info('------------------------------------------');
        $this->command->info('👤 Super Admin:  admin@saas.com / abc@1234');
        $this->command->info('🏢 Agency Owner: owner@demo.com / abc@1234');
        $this->command->info('👷 Staff User:   staff@demo.com / abc@1234');
        $this->command->info('------------------------------------------');
    }
}
