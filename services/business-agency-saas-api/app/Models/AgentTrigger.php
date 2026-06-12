<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTrigger extends Model
{
    public $fillable = ['tenant_id', 'ai_agent_id', 'event_class', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relationship: The AI Agent that should be executed.
     */
    public function aiAgent(): BelongsTo
    {
        // Ensure the foreign key matches your migration (ai_agent_id)
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    /**
     * Relationship: The Tenant that owns this trigger.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
