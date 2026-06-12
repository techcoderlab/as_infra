<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TenantController extends Controller
{
    protected $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    public function index(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // Eager load relationships for efficiency
        return response()->json([
            'tenants' => Tenant::with('plans', 'settings:id,tenant_id,crm_config')->orderByDesc('id')->get(),
            'available_plans' => Plan::with('modules')->get(), // Send plans with their modules
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:255', 'unique:tenants,domain'],
            'status' => ['nullable', 'in:active,suspended'],
            'plan_id' => ['sometimes', 'nullable', 'exists:plans,id'],
            'expires_at' => ['nullable', 'date'],

            'crm_config' => ['nullable', 'array'],
            'crm_config.entity_name_singular' => ['required_with:crm_config', 'string'],
            'crm_config.entity_name_plural' => ['required_with:crm_config', 'string'],
            'crm_config.statuses' => ['required_with:crm_config', 'array', 'min:1'],
            'crm_config.statuses.*.slug' => ['required_with:crm_config', 'string', 'distinct'],
            'crm_config.statuses.*.label' => ['required_with:crm_config', 'string'],
            'crm_config.statuses.*.color' => ['required_with:crm_config', 'string'],
        ]);

        $tenant = Tenant::create([
            'name' => $validated['name'],
            'domain' => $validated['domain'] ?? null,
            'status' => $validated['status'] ?? 'active',
        ]);

        // 1. Update Pivot
        // We use sync to ensure a tenant only has ONE active plan at a time (or attach for many)
        // For this architecture, let's assume one active plan:
        if (isset($validated['plan_id'])) {
            $tenant->plans()->sync([
                $validated['plan_id'] => ['expires_at' => $validated['expires_at'] ?? null],
            ]);
        }

        if (isset($validated['crm_config'])) {
            TenantSetting::create([
                'tenant_id' => $tenant->id,
                'crm_config' => $validated['crm_config'],
                // 'client_theme' => json_decode('{"primary":"#3490dc","secondary":"#ffed4a","font":"Arial, sans-serif"}'),
            ]);
        }

        return response()->json($tenant->load('plans.modules', 'settings'), Response::HTTP_CREATED);
    }

    public function update(Request $request, Tenant $tenant)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255', 'unique:tenants,domain,'.$tenant->id],
            'status' => ['sometimes', 'in:active,suspended'],
            'plan_id' => ['sometimes', 'exists:plans,id'],
            'expires_at' => ['nullable', 'date'],

            'crm_config' => ['nullable', 'array'],
            'crm_config.entity_name_singular' => ['required_with:crm_config', 'string'],
            'crm_config.entity_name_plural' => ['required_with:crm_config', 'string'],
            'crm_config.statuses' => ['required_with:crm_config', 'array', 'min:1'],
            'crm_config.statuses.*.slug' => ['required_with:crm_config', 'string', 'distinct'],
            'crm_config.statuses.*.label' => ['required_with:crm_config', 'string'],
            'crm_config.statuses.*.color' => ['required_with:crm_config', 'string'],
        ]);

        $tenant->update($validated);

        if (isset($validated['plan_id'])) {

            // 1. Update Pivot
            // We use sync to ensure a tenant only has ONE active plan at a time (or attach for many)
            // For this architecture, let's assume one active plan:
            $tenant->plans()->sync([
                $validated['plan_id'] => ['expires_at' => $validated['expires_at'] ?? null],
            ]);

            // 2. TRIGGER CACHE BUSTING
            // This ensures the next request from this tenant gets the NEW module limits immediately.
            $this->tenantManager->clearTenantCache($tenant->id);
        }

        if (isset($validated['crm_config'])) {
            TenantSetting::updateOrCreate(
                ['tenant_id' => $tenant->id],
                ['crm_config' => $validated['crm_config']]
            );
        }

        return response()->json($tenant->load('plans.modules', 'settings'));
    }

    public function destroy(Request $request, Tenant $tenant)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // 1. Safety Check: Prevent deletion if tenants are actively using this plan
        // This prevents "orphaning" tenants or breaking their access via cascade deletes
        if ($tenant->users()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this tenant because it is currently assigned to clients. Please reassign them to a different tenant first or delete them entirely.',
            ], 409); // 409 Conflict
        }

        $tenant->delete();

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    public function getModulesForTenant(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Ensure the user has a currently active tenant context.
        if (! $user->currentTenant) {
            return response()->json(['message' => 'No active tenant context.'], 400);
        }

        // Eager load the plan and its modules for the current tenant.
        $tenant = $user->currentTenant()->with('plans.modules')->first();

        if (! $tenant || ! $tenant->plans->first()) {
            return response()->json(['modules' => []]);
        }

        // The logic assumes one active plan per tenant as per recent refactoring.
        $modules = $tenant->plans->first()->modules;

        return response()->json($modules);
    }

    public function updateCrmConfig(Request $request)
    {
        $validated = $request->validate([
            'entity_name_singular' => 'required|string|max:50',
            'entity_name_plural' => 'required|string|max:50',
            'statuses' => 'required|array|min:1',
            'statuses.*.slug' => 'required|string|distinct',
            'statuses.*.label' => 'required|string',
            'statuses.*.color' => 'required|string',
        ]);

        $settings = TenantSetting::firstOrCreate(
            ['tenant_id' => $request->user()->current_tenant_id]
        );

        $settings->crm_config = $validated;
        $settings->save();

        return response()->json($settings->crm_config);
    }
}
