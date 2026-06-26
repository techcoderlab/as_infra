<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiJob extends Model
{
    protected $fillable = [
        'tenant_id',
        'job_uuid',
        'agent_slug',
        'target_id',
        'target_type', // <--- Added
        'status',
        'payload',
        'result',
        'error_message',
        'attempts',    // <--- Added
        'started_at',  // <--- Added
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'encrypted:array',
        // 'payload' => 'encrypted:json',
        'result' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Helper to get the actual target model (Lead, Order, etc.)
     */
    public function target()
    {
        return $this->morphTo();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    // Friendly status for the UI
    public function getFriendlyStatusAttribute()
    {
        return match ($this->status) {
            'pending' => 'Preparing workspace...',
            'processing' => 'Agent is thinking and using tools...',
            'completed' => 'Task finished successfully.',
            'failed' => 'Something went wrong.',
            default => 'Unknown state'
        };
    }
}
