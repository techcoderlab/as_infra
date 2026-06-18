<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles; // Spatie

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes; // Add HasRoles and SoftDeletes

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    public function isNotSuperAdmin(): bool
    {
        return ! $this->isSuperAdmin();
    }

    public function isTenantSuperAdmin(): bool
    {
        return $this->hasRole('agency_owner');
    }

    public function isTenantStaff(): bool
    {
        return ! $this->isTenantSuperAdmin();
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')->withPivot('role', 'is_primary');
    }

    public function currentTenant()
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    public function loggedInFromApp()
    {
        $currentToken = $this->currentAccessToken();

        return [$currentToken?->name === 'api', $currentToken];
    }

    public function loggedInFromString()
    {

        $loggedInFromAppArr = $this->loggedInFromApp();

        return $loggedInFromAppArr[0] ? 'app' : "'{$loggedInFromAppArr[1]?->name}' api key";
    }
}
