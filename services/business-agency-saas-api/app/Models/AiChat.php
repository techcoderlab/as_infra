<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model; // Using your existing Trait

class AiChat extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'name',
        'webhook_url',
        'webhook_secret',
        'avatar_url',
        'welcome_message',
        'ai_agent_id',
    ];

    public function agent()
    {
        // This links the chat room to a specific "Brain" (Ayesha, Support, etc.)
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }
}
