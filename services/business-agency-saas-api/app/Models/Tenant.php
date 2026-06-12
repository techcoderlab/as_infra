<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'domain',
        'status',
        'slug',
        'is_active',
    ];

    // public function users()
    // {
    //     return $this->belongsToMany(User::class, 'tenant_user')->withPivot('role', 'is_primary');
    // }

    // public function plans()
    // {
    //     return $this->belongsToMany(Plan::class, 'plan_tenant');
    // }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_tenant')
            ->withPivot('expires_at', 'grace_period_days', 'ai_credits_used')
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot('role', 'is_primary');
    }

    public function settings()
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function getAiLimitAttribute()
    {
        return Cache::remember("tenant_{$this->getKey()}_ai_limit", 3600, function () {
            return DB::table('plan_tenant')
                ->join('plans', 'plan_tenant.plan_id', '=', 'plans.id')
                ->where('tenant_id', $this->getKey())
                ->value('ai_credit_limit') ?? 0;
        });
    }

    public function canTenantUseAi(): bool
    {
        $limit = $this->getAiLimitAttribute();

        if ($limit === 0) {
            return false;
        }
        if ($limit === -1) {
            return true;
        } // Unlimited

        // Only query the 'used' part from DB (no join needed here)
        $used = DB::table('plan_tenant')->where('tenant_id', $this->getKey())->value('ai_credits_used');

        return (int) $used < (int) $limit;
    }
}
