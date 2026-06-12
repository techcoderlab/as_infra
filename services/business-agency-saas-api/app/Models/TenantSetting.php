<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        // 'client_theme',
        'crm_config',
        // 'api_creds',
        'ai_provider_default',
    ];

    protected $casts = [
        // 'client_theme' => 'array',
        'crm_config' => 'array',
        // 'api_creds' => 'encrypted:array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
