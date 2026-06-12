<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = ['ai_chat_id', 'user_id', 'role', 'content', 'files'];

    protected $casts = [
        'files' => 'array',
    ];
}
