<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\LeadChatSession;
use App\Contracts\Events\ShouldTriggerAgent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WhatsAppMessageReceived implements ShouldTriggerAgent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // protected const PLATFORM = 'whatsapp';

    /**
     * Create a new event instance.
     */
    public function __construct(public Lead $model, public LeadChatSession $session) {}

    public function getTargetModel(): Lead
    {
        return $this->model;
    }
}
