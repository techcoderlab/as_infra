<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class KnowledgeSource extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_knowledge_sources';

    protected $fillable = [
        'tenant_id',
        'title',
        'source_type',
        'source_url',
        'content',
        'is_active',
        'status',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function aiAgents(): BelongsToMany
    {
        return $this->belongsToMany(AiAgent::class, 'agent_knowledge_source');
    }
}
