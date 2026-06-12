<?php

namespace App\Services;

use App\Events\WhatsAppMessageReceived;
use App\Models\Lead;
use App\Models\LeadChatSession;
use Illuminate\Support\Facades\Log;
use Throwable;

class TriggerAiAgentJobService
{
    /**
     * Trigger the AI Agent logic synchronously.
     * The robust locking is now managed by DebounceService and AiGateway.
     */
    public function trigger(Lead $lead, LeadChatSession $session, string $sessionKey): void
    {
        try {
            Log::info("[TriggerAiAgentJobService] Dispatching event for session: {$session->platform_user_id}");

            // Dispatch the event that starts the AI chain
            event(new WhatsAppMessageReceived($lead, $session));
        } catch (Throwable $e) {
            Log::error('[TriggerAiAgentJobService] Error: '.$e->getMessage());

            // If it fails structurally, we should probably inform DebounceService
            // but for now, logging is the safest simplified path.
        }
    }
}
