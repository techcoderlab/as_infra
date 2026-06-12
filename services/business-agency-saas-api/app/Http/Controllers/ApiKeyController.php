<?php

namespace App\Http\Controllers;

use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApiKeyController extends Controller
{
    /**
     * Helper to map raw scopes (e.g., "leads:view") to readable UI groups.
     */
    private function buildPermissionGroups(array $activeModules, array $allowedScopes): array
    {
        // We still use config for the *Descriptions* of actions (view/create/delete),
        // but the *Modules* themselves come from the Database.
        $actionLabels = [
            'view' => ['label' => 'View',   'desc' => 'Read access to :module'],
            'write' => ['label' => 'Create', 'desc' => 'Create new :module'],
            'update' => ['label' => 'Edit',   'desc' => 'Update existing :module'],
            'delete' => ['label' => 'Delete', 'desc' => 'Delete :module'],
        ];

        $groups = [];

        foreach ($activeModules as $module) {
            $moduleId = $module['slug'];
            $moduleLabel = $module['name'];

            // 1. Find all scopes that start with "leads:" (for example)
            $scopes = array_filter($allowedScopes, fn ($s) => str_starts_with($s, $moduleId.':'));

            $mappedScopes = [];

            foreach ($scopes as $scope) {
                // e.g., "leads:view" -> $action = "view"
                [$mod, $action] = explode(':', $scope);

                if (! isset($actionLabels[$action])) {
                    continue;
                }

                $label = $actionLabels[$action]['label']; // "View"

                // Dynamic description: "Read access to leads"
                $descModuleName = strtolower($moduleLabel);
                $desc = str_replace(':module', $descModuleName, $actionLabels[$action]['desc']);

                $mappedScopes[] = [
                    'id' => $scope,
                    'label' => $label,
                    'desc' => $desc,
                ];
            }

            if (! empty($mappedScopes)) {
                $groups[] = [
                    'name' => $moduleLabel, // e.g., "Leads Management" from DB
                    'scopes' => $mappedScopes,
                ];
            }
        }

        return $groups;
    }

    /**
     * List all active API keys for the user.
     */
    public function index(Request $request)
    {
        // $this->authorize('viewAny', ApiKeyPolicy::class);

        // 1. Get the Tenant from the Context (Singleton)
        $tenantManager = app(TenantManager::class);
        $tenant = $tenantManager->getActiveTenant();

        $activeModules = [];

        // 2. Fetch Modules from the Database (Tenant -> Plan -> Modules)
        if ($tenant && $tenant->plans->first()) {
            // We map them to a standard array format for the builder
            $activeModules = $tenant->plans->first()->modules
                ->where('slug', '!=', 'api_keys') // Don't allow generating keys for the key manager itself
                ->map(function ($module) {
                    return [
                        'slug' => $module->slug,
                        'name' => $module->name ?? ucfirst($module->slug), // Fallback if name is missing
                    ];
                })->values()->toArray();
        }

        // 3. Return keys + dynamically built permission groups
        return response()->json([
            'keys' => $request->user()->tokens()
                ->where('name', '!=', 'api')
                ->select('id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at')
                ->orderByDesc('created_at')
                ->get(),
            'permission_groups' => $this->buildPermissionGroups(
                $activeModules,
                config('sanctum.allowed_scopes', []), // The universe of valid scopes
            ),
        ]);
    }

    /**
     * Create a new API key with specific permissions and expiry.
     */
    public function store(Request $request)
    {
        $this->authorize('api_keys.write');

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'sometimes|required|array|min:1',
            'abilities.*' => [Rule::in(config('sanctum.allowed_scopes'))],
            'expiration_days' => 'nullable|integer|in:30,60,90', // Null implies 'never'
        ]);

        // Calculate Expiration
        $expiresAt = $validated['expiration_days']
            ? now()->addDays($validated['expiration_days'])
            : null;

        // Create token with 3rd argument for expiration
        $token = $request->user()->createToken(
            $validated['name'],
            isset($validated['abilities']) ? $validated['abilities'] : ['*'],
            $expiresAt
        );

        return response()->json([
            'message' => 'API Key generated successfully',
            'token' => $token->plainTextToken,
            'entry' => $token->accessToken,
        ], 201);
    }

    /**
     * Update an existing API key (Rename, Scopes, or Expiry).
     */
    public function update(Request $request, string $tokenId)
    {
        $this->authorize('api_keys.update');

        // Ensure user owns the token
        $token = $request->user()->tokens()->where('id', $tokenId)->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'abilities' => 'sometimes|required|array|min:1',
            'abilities.*' => [Rule::in(config('sanctum.allowed_scopes'))],
            'expiration_days' => 'nullable|integer|in:30,60,90', // Handle 'never' (null) carefully in frontend
        ]);

        // Update fields if present
        if (isset($validated['name'])) {
            $token->name = $validated['name'];
        }

        if (isset($validated['abilities'])) {
            $token->abilities = $validated['abilities'];
        } else {
            $token->abilities = ['*'];
        }

        // Logic for updating expiry:
        // If 'expiration_days' key exists in request (even if null), update it.
        // We calculate new expiry from NOW.
        if (array_key_exists('expiration_days', $validated)) {
            $token->expires_at = $validated['expiration_days']
                ? now()->addDays($validated['expiration_days'])
                : null;
        }

        $token->save();

        return response()->json([
            'message' => 'API Key updated successfully',
            'entry' => $token,
        ]);
    }

    /**
     * Rotate an API Key.
     * Generates a NEW token with the same configuration, deletes the old one.
     */
    public function rotate(Request $request, string $tokenId)
    {
        $this->authorize('api_keys.write');

        // 1. Find the old token
        $oldToken = $request->user()->tokens()->where('id', $tokenId)->firstOrFail();

        // 2. Calculate new expiration based on original lifespan
        // If the old token had an expiry, we grant the same duration starting NOW.
        $newExpiresAt = null;
        if ($oldToken->expires_at && $oldToken->created_at) {
            $lifespanInSeconds = $oldToken->created_at->diffInSeconds($oldToken->expires_at);
            $newExpiresAt = now()->addSeconds($lifespanInSeconds);
        }

        // 3. Create the NEW token
        $newToken = $request->user()->createToken(
            $oldToken->name,
            $oldToken->abilities,
            $newExpiresAt
        );

        // 4. Delete the OLD token
        $oldToken->delete();

        return response()->json([
            'message' => 'API Key rotated successfully. Old key is now invalid.',
            'token' => $newToken->plainTextToken, // The new secret
            'entry' => $newToken->accessToken,
        ]);
    }

    /**
     * Revoke (delete) an API key.
     */
    public function destroy(Request $request, string $tokenId)
    {
        $this->authorize('api_keys.delete');

        $request->user()->tokens()->where('id', $tokenId)->delete();

        return response()->json(['message' => 'API Key revoked']);
    }
}
