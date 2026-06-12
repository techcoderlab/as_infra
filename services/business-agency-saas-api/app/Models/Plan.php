<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function modules()
    {
        return $this->belongsToMany(Module::class, 'module_plan')->withPivot('limit');
    }

    public function tenants()
    {
        return $this->belongsToMany(Tenant::class, 'plan_tenant')->withPivot('expires_at')->withTimestamps();
    }
}
