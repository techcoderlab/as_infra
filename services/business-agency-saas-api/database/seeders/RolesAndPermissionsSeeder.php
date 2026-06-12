<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Define Module-Based Permissions
        // Format: 'verb module' (matches the Policy checks)
        $permissions = [
            // Leads
            'view leads',
            'write leads',
            'update leads',
            'delete leads',

            // Forms
            'view forms',
            'write forms',
            'update forms',
            'delete forms',

            // Webhooks
            'view webhooks',
            'write webhooks',
            'update webhooks',
            'delete webhooks',

            // AI Chats
            'view ai_chats',
            'write ai_chats',
            'update ai_chats',
            'delete ai_chats',

            // AI Agents
            'view ai_agents',
            'write ai_agents',
            'update ai_agents',
            'delete ai_agents',

            // Integrations
            'view integrations',
            'write integrations',
            'update integrations',
            'delete integrations',

            // API Keys
            'view api_keys',
            'write api_keys',
            'update api_keys',
            'delete api_keys',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // 2. Create Standard Roles & Assign Permissions

        // Super Admin (Global Access)
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin']);
        // Super admin usually bypasses checks via Gate::before,
        // but we can assign all for clarity.
        // $superAdmin->givePermissionTo(Permission::all());

        // Agency Owner (Full Tenant Access)
        $agencyOwner = Role::firstOrCreate(['name' => 'agency_owner']);
        $agencyOwner->givePermissionTo(Permission::all());

        // // Agency Manager (Restricted)
        // $agencyManager = Role::firstOrCreate(['name' => 'agency_manager']);
        // $agencyManager->givePermissionTo([
        //     'view leads', 'write leads',
        //     'view forms', 'write forms',
        //     'view ai-chats',
        //     'view webhooks'
        // ]);

        // Agency Viewer (Read Only)
        $agencyViewer = Role::firstOrCreate(['name' => 'staff']);
        $agencyViewer->givePermissionTo([
            'view leads',
        ]);

        // Log Output
        $this->command->info('------------------------------------------');
        $this->command->info('✅ Database Permissions Seeding Complete!');
        $this->command->info('------------------------------------------');
    }
}
