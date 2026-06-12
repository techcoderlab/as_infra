<?php

namespace App\Traits;

use App\Services\TenantManager;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {

        static::creating(function ($model) {
            $tenantManager = app(TenantManager::class);
            if (! $model->tenant_id && $tenant = $tenantManager->getActiveTenant()) {
                $model->tenant_id = $tenant->id;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantManager = app(TenantManager::class);
            if ($tenant = $tenantManager->getActiveTenant()) {
                $builder->where($builder->getQuery()->from.'.tenant_id', $tenant->id);
            }
        });
    }

    // public function tenant()
    // {
    //     return $this->belongsTo(\App\Models\Tenant::class);
    // }
}
