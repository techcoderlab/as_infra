<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        /** @var \App\Models\User|null $user */
        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        // Ensure a current tenant is set for the user
        if (! $user->current_tenant_id && $user->tenants()->exists()) {
            $user->current_tenant_id = $user->tenants()->first()->id;
            $user->save();
        }

        $user->load('currentTenant');

        if ($user->currentTenant && $user->currentTenant->status === 'suspended') {
            return response()->json([
                'message' => 'Your account is suspended. Please contact the administrator.',
            ], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
        ]);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:255'],
            'tenant_domain' => ['nullable', 'string', 'max:255', 'unique:tenants,domain'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            $tenant = Tenant::create([
                'name' => $validated['tenant_name'],
                'domain' => $validated['tenant_domain'] ?? null,
                'status' => 'active',
            ]);

            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'], // Hash is handled by model accessor
            ]);

            // Link user to tenant
            $user->tenants()->attach($tenant->id, ['role' => 'agency_owner', 'is_primary' => true]);

            // Set the user's current active tenant
            $user->current_tenant_id = $tenant->id;
            $user->save();

            $user->assignRole('agency_owner');

            return $user;
        });

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
        ], 201);
    }

    public function n8nToken(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $tokenName = $request->input('name', 'n8n-webhook');

        $token = $user->createToken($tokenName, ['n8n:webhook'])->plainTextToken;

        return response()->json([
            'token' => $token,
        ]);
    }
}
