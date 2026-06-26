<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    protected $fillable = ['tenant_id', 'service', 'key', 'value', 'is_active', 'is_brain'];

    protected $casts = [
        // This ensures secrets are never plain-text in your DB
        'value' => 'encrypted:json',
        'is_active' => 'boolean',
        'is_brain' => 'boolean',
    ];

    protected $hidden = [
        'value',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
