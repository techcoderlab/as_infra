<?php

namespace App\Http\Controllers;

use App\Services\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BootstrapController extends Controller
{
    public function __invoke(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $tenantManager = app(TenantManager::class);

        $user->load('roles', 'permissions');
        // $permissions = $user->getAllPermissions()->pluck('name');

        $moduleSlugs = $tenantManager->getEnabledModules();

        // Map slugs to rich UI objects for the sidebar
        $moduleNav = collect($moduleSlugs)->map(function ($slug) {
            return $this->getModuleMetadata($slug);
        })->filter()->values();

        return response()->json([
            'user' => $user,
            'permissions' => $tenantManager->getUserPermissions($user),
            'active_tenant' => $tenantManager->getActiveTenant(),
            'enabled_modules' => $moduleSlugs,
            'module_nav' => $moduleNav, // This was previously empty
        ]);
    }

    /**
     * Returns an associative array containing metadata for the given module slug.
     * The returned array will contain the following keys: 'slug', 'label', 'route', 'icon'.
     * If the given slug is not recognized, the function will return null.
     *
     * @param  string  $slug  The module slug to retrieve metadata for.
     * @return array|null
     */
    private function getModuleMetadata($slug)
    {

        $metadata = config('modules.metadata', []);

        return isset($metadata[$slug]) ? array_merge(['slug' => $slug], $metadata[$slug]) : null;
    }
}
