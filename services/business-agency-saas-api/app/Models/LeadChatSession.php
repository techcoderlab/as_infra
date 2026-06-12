<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LeadChatSession extends Model
{
    use BelongsToTenant;
    use HasFactory;

    // use HasUuids;
    use HasVersion4Uuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'lead_id',
        'tenant_id',
        'platform',
        'platform_user_id',
        'status',
        'last_interaction_at',
        'thread_id',
        'context_data',
        'message_count',
    ];

    protected $casts = [
        'context_data' => 'array',
        'last_interaction_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
