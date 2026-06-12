<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlanController extends Controller
{
    protected $tenantManager;

    public function __construct(TenantManager $tenantManager)
    {
        $this->tenantManager = $tenantManager;
    }

    // --- PLANS & MODULES MANAGEMENT ---

    public function index()
    {
        $user = request()->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return response()->json([
            'plans' => Plan::with('modules')->get(),
            'modules' => Module::all(),
        ]);
    }

    public function storePlan(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:plans,slug',
            'price' => 'numeric',
            'modules' => 'array', // format: [{id: 1, limit: 10}, {id: 2, limit: -1}]
        ]);

        $plan = Plan::create($validated);

        // Sync Modules with Limits
        $pivotData = [];
        foreach ($request->modules as $mod) {
            $pivotData[$mod['id']] = ['limit' => $mod['limit']];
        }
        $plan->modules()->sync($pivotData);

        return response()->json($plan);
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => 'required|string',
            // Unique check must ignore the current plan's ID
            'slug' => 'required|string|unique:plans,slug,'.$plan->id,
            'price' => 'numeric',
            'modules' => 'array', // format: [{id: 1, limit: 10}, ...]
        ]);

        // 1. Update basic details
        $plan->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'price' => $validated['price'],
            // -1 = Unlimited, 0 = Disabled, >0 = Limit
            'ai_credit_limit' => $request->ai_credit_limit,
        ]);

        // 2. Sync Modules with Limits
        if (isset($request->modules)) {
            $pivotData = [];
            foreach ($request->modules as $mod) {
                $pivotData[$mod['id']] = ['limit' => $mod['limit']];
            }
            $plan->modules()->sync($pivotData);
        }

        // 3. CRITICAL: Cache Busting for All Subscribers
        // Since the plan changed, every tenant using this plan needs their cache cleared
        // to see the new limits/modules immediately.
        $plan->tenants()->chunk(50, function ($tenants) {
            foreach ($tenants as $tenant) {
                $this->tenantManager->clearTenantCache($tenant->id);
            }
        });

        return response()->json($plan->load('modules'));
    }

    public function destroyPlan(Request $request, Plan $plan)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        // 1. Safety Check: Prevent deletion if tenants are actively using this plan
        // This prevents "orphaning" tenants or breaking their access via cascade deletes
        if ($plan->tenants()->exists()) {
            return response()->json([
                'message' => 'Cannot delete this plan because it is currently assigned to tenants. Please reassign them to a different plan first.',
            ], 409); // 409 Conflict
        }

        // 2. Delete the plan
        // (Cascade on delete in migration will automatically clean up related module_plan records)
        $plan->delete();

        return response()->json(['message' => 'Plan deleted successfully.']);
    }

    public function storeModule(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $module = Module::create($request->validate([
            'name' => 'required|string',
            'slug' => 'required|string|unique:modules,slug',
        ]));

        return response()->json($module);
    }

    // --- ASSIGN PLAN TO TENANT (Triggers Cache Busting) ---

    public function assignPlan(Request $request, Tenant $tenant)
    {
        $user = $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'expires_at' => 'nullable|date',
        ]);

        // 1. Update Pivot
        // We use sync to ensure a tenant only has ONE active plan at a time (or attach for many)
        // For this architecture, let's assume one active plan:
        $tenant->plans()->sync([
            $validated['plan_id'] => ['expires_at' => $validated['expires_at'] ?? null],
        ]);

        // 2. TRIGGER CACHE BUSTING
        // This ensures the next request from this tenant gets the NEW module limits immediately.
        $this->tenantManager->clearTenantCache($tenant->id);

        return response()->json(['message' => 'Plan assigned and cache updated.']);
    }
}
