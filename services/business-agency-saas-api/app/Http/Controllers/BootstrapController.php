<?php

namespace App\Http\Controllers;

use App\Services\TenantManager;
use App\Models\Module;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BootstrapController extends Controller
{
    public function __invoke(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $tenantManager = app(TenantManager::class);


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
        $module = Module::where('slug', $slug)->first();

        if ($module) {
            return [
                'slug' => $module->slug,
                'label' => $module->name,
                'route' => $module->route,
                'icon' => $module->icon,
            ];
        }

        return null;
    }
}
