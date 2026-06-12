<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiAuditLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
