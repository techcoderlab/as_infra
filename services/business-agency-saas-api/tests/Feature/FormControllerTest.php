<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_form_with_fields_needed()
    {
        \Illuminate\Support\Facades\Gate::before(function () {
            return true;
        });

        // 1. Setup Tenant & User
        $tenant = new Tenant;
        $tenant->name = 'Test Tenant';
        $tenant->save();

        $user = new User;
        $user->tenant_id = $tenant->id;
        $user->name = 'Test User';
        $user->email = 'test@example.com';
        $user->password = bcrypt('password');
        $user->save();

        // Link user to tenant for the gate
        \DB::table('tenant_user')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'agency_owner',
            'is_primary' => true,
        ]);

        // Mock TenantManager for the module check
        $this->mock(\App\Services\TenantManager::class, function ($mock) {
            $mock->shouldReceive('isModuleEnabled')->andReturn(true);
            $mock->shouldReceive('getActiveTenant')->andReturn(\App\Models\Tenant::first());
        });

        // 2. Authenticate
        $this->actingAs($user);

        // 3. Bypass plan/module checks for testing
        $this->app->instance(\App\Http\Middleware\CheckPlanExpiry::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });
        $this->app->instance(\App\Http\Middleware\CheckTenantModule::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });
        $this->app->instance(\App\Http\Middleware\CheckTenantStatus::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });
        $this->app->instance(\App\Http\Middleware\EnsureUserHasTenantAccess::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });

        // 4. Payload
        $mapping = ['foo' => 'Bar Label'];
        $payload = [
            'name' => 'New Form with Mapping',
            'form_source' => 'system',
            'is_active' => true,
            'fields_needed' => $mapping,
        ];

        // 5. Request
        $response = $this->postJson('/api/forms', $payload);

        // 6. Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('forms', [
            'name' => 'New Form with Mapping',
            'tenant_id' => $tenant->id,
        ]);

        $form = Form::where('name', 'New Form with Mapping')->first();
        $this->assertNotNull($form->fields_needed);
        $this->assertEquals('Bar Label', $form->fields_needed['foo']);
    }

    public function test_update_form_with_fields_needed()
    {
        \Illuminate\Support\Facades\Gate::before(function () {
            return true;
        });

        // 1. Setup Tenant & User
        $tenant = new Tenant;
        $tenant->name = 'Test Tenant';
        $tenant->save();

        $user = new User;
        $user->tenant_id = $tenant->id;
        $user->name = 'Test User';
        $user->email = 'test2@example.com';
        $user->password = bcrypt('password');
        $user->save();

        // Link user to tenant for the gate
        \DB::table('tenant_user')->insert([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'role' => 'agency_owner',
            'is_primary' => true,
        ]);

        // Mock TenantManager for the module check
        $this->mock(\App\Services\TenantManager::class, function ($mock) {
            $mock->shouldReceive('isModuleEnabled')->andReturn(true);
            $mock->shouldReceive('getActiveTenant')->andReturn(\App\Models\Tenant::first());
        });

        $form = Form::create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Form',
            'form_source' => 'system',
            'is_active' => true,
            'schema' => [],
        ]);

        $this->actingAs($user);

        // Bypass plan/module checks for testing
        $this->app->instance(\App\Http\Middleware\CheckPlanExpiry::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });
        $this->app->instance(\App\Http\Middleware\CheckTenantModule::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });
        $this->app->instance(\App\Http\Middleware\CheckTenantStatus::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });
        $this->app->instance(\App\Http\Middleware\EnsureUserHasTenantAccess::class, new class
        {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });

        $mapping = ['updated' => 'Updated Label'];
        $payload = [
            'name' => 'Old Form',
            'form_source' => 'system',
            'fields_needed' => $mapping,
        ];

        $response = $this->putJson("/api/forms/{$form->id}", $payload);

        $response->assertStatus(200);

        $form->refresh();
        $this->assertNotNull($form->fields_needed);
        $this->assertEquals('Updated Label', $form->fields_needed['updated']);
    }
}
