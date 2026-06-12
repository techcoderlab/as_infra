<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadChatSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppMessageReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // protected const PLATFORM = 'whatsapp';

    /**
     * Create a new event instance.
     */
    public function __construct(public Lead $model, public LeadChatSession $session) {}
}
