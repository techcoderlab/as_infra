<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = ['ai_chat_id', 'user_id', 'role', 'content', 'files', 'platform_message_id', 'metadata'];

    protected $casts = [
        'files' => 'array',
        'metadata' => 'array',
    ];

    public function chat()
    {
        return $this->belongsTo(AiChat::class, 'ai_chat_id');
    }
}
