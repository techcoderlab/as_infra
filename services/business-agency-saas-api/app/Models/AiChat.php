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
        'tenant_id',
        'webhook_url',
        'webhook_secret',
        'avatar_url',
        'welcome_message',
        'ai_agent_id',
        'target_type',
        'target_id',
        'platform',
        'status',
    ];

    public function agent()
    {
        // This links the chat room to a specific "Brain" (Ayesha, Support, etc.)
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'ai_chat_id');
    }

    public function target()
    {
        return $this->morphTo();
    }

    public function chatSession()
    {
        return $this->hasOne(LeadChatSession::class, 'ai_chat_id');
    }

    public function scopeForTarget($query, string $type, int $id)
    {
        return $query->where('target_type', $type)->where('target_id', $id);
    }

    public static function findOrCreateForTarget(int $tenantId, string $targetType, int $targetId, string $platform, ?int $agentId = null): self
    {
        return self::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'platform' => $platform,
            ],
            [
                'name' => 'Chat with ' . ucfirst($targetType) . ' #' . $targetId,
                'ai_agent_id' => $agentId,
                'status' => 'active',
                'webhook_url' => '', // Generic since it's an external chat
            ]
        );
    }
}
