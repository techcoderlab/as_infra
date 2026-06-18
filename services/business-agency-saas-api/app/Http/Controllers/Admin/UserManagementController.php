<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

// ─────────────────────────────────────────────────────
// Module   : Super Admin User Management
// ─────────────────────────────────────────────────────
class UserManagementController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = User::query()
            ->with(['tenants', 'roles'])
            ->withTrashed(); // Include soft deleted users

        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('tenant_id') && $request->tenant_id) {
            $query->whereHas('tenants', function ($q) use ($request) {
                $q->where('tenant_id', $request->tenant_id);
            });
        }

        if ($request->has('role') && $request->role) {
            $query->whereHas('roles', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'global_role' => ['nullable', 'string', Rule::in(['super_admin', 'agency_owner', 'staff'])],
            'tenant_id' => 'nullable|exists:tenants,id',
            'tenant_role' => ['nullable', 'string', Rule::in(['agency_owner', 'staff'])],
        ]);

        DB::beginTransaction();
        try {
            $user = clone new User();
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            $user->password = Hash::make($validated['password']);
            
            // Set current tenant if provided
            if (!empty($validated['tenant_id'])) {
                $user->current_tenant_id = $validated['tenant_id'];
            }
            
            $user->save();

            // Assign global role
            if (!empty($validated['global_role'])) {
                $user->assignRole($validated['global_role']);
            } else {
                // Default global role if none provided but tenant is
                $user->assignRole('staff');
            }

            // Assign to tenant if provided
            if (!empty($validated['tenant_id'])) {
                $tenantRole = $validated['tenant_role'] ?? 'staff';
                $user->tenants()->attach($validated['tenant_id'], [
                    'role' => $tenantRole,
                    'is_primary' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json($user->load(['tenants', 'roles']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create user.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::withTrashed()->with(['tenants', 'roles'])->findOrFail($id);
        return response()->json($user);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $user = User::withTrashed()->findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'global_role' => ['nullable', 'string', Rule::in(['super_admin', 'agency_owner', 'staff'])],
            'is_active' => 'boolean' // Map to restoring/deleting soft deletes
        ]);

        DB::beginTransaction();
        try {
            $user->name = $validated['name'];
            $user->email = $validated['email'];
            
            if (!empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            $user->save();

            // Handle global role update
            if (isset($validated['global_role'])) {
                $user->syncRoles([$validated['global_role']]);
            }

            // Handle soft delete / restore via is_active toggle
            if (isset($validated['is_active'])) {
                if ($validated['is_active'] && $user->trashed()) {
                    $user->restore();
                } elseif (!$validated['is_active'] && !$user->trashed()) {
                    $user->delete();
                }
            }

            DB::commit();

            return response()->json($user->load(['tenants', 'roles']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update user.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage. (Soft delete)
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        
        // Prevent deleting oneself
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot delete yourself.'], 403);
        }

        $user->delete(); // Soft delete

        return response()->json(['message' => 'User suspended successfully.']);
    }

    /**
     * Assign a user to a tenant.
     */
    public function assignTenant(Request $request, string $userId)
    {
        $user = User::withTrashed()->findOrFail($userId);

        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'role' => ['required', 'string', Rule::in(['agency_owner', 'staff'])],
            'is_primary' => 'boolean'
        ]);

        $tenantId = $validated['tenant_id'];

        if ($user->tenants()->where('tenant_id', $tenantId)->exists()) {
            return response()->json(['message' => 'User is already assigned to this tenant.'], 409);
        }

        $isPrimary = $validated['is_primary'] ?? false;

        // If setting as primary, unset others
        if ($isPrimary) {
            $user->tenants()->updateExistingPivot($user->tenants->pluck('id')->toArray(), ['is_primary' => false]);
            $user->current_tenant_id = $tenantId;
            $user->save();
        }

        $user->tenants()->attach($tenantId, [
            'role' => $validated['role'],
            'is_primary' => $isPrimary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($user->load('tenants'));
    }

    /**
     * Remove a user from a tenant.
     */
    public function removeTenant(string $userId, string $tenantId)
    {
        $user = User::withTrashed()->findOrFail($userId);

        if (!$user->tenants()->where('tenant_id', $tenantId)->exists()) {
            return response()->json(['message' => 'User is not assigned to this tenant.'], 404);
        }

        $user->tenants()->detach($tenantId);

        // If we removed their current tenant, nullify it or set another one
        if ($user->current_tenant_id == $tenantId) {
            $nextPrimary = $user->tenants()->first();
            $user->current_tenant_id = $nextPrimary ? $nextPrimary->id : null;
            if ($nextPrimary) {
                $user->tenants()->updateExistingPivot($nextPrimary->id, ['is_primary' => true]);
            }
            $user->save();
        }

        return response()->json(['message' => 'User removed from tenant.']);
    }

    /**
     * Update the role of a user in a specific tenant.
     */
    public function updateTenantRole(Request $request, string $userId, string $tenantId)
    {
        $user = User::withTrashed()->findOrFail($userId);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(['agency_owner', 'staff'])],
            'is_primary' => 'boolean'
        ]);

        if (!$user->tenants()->where('tenant_id', $tenantId)->exists()) {
            return response()->json(['message' => 'User is not assigned to this tenant.'], 404);
        }

        $updateData = ['role' => $validated['role']];

        if (isset($validated['is_primary'])) {
            $isPrimary = $validated['is_primary'];
            $updateData['is_primary'] = $isPrimary;

            if ($isPrimary) {
                $user->tenants()->updateExistingPivot($user->tenants->pluck('id')->toArray(), ['is_primary' => false]);
                $user->current_tenant_id = $tenantId;
                $user->save();
            } elseif ($user->current_tenant_id == $tenantId) {
                // If they unset primary but it was their current, we should ideally handle this
                // For now, keep current_tenant_id if they just unset primary flag
            }
        }

        $user->tenants()->updateExistingPivot($tenantId, $updateData);

        return response()->json($user->load('tenants'));
    }
}

