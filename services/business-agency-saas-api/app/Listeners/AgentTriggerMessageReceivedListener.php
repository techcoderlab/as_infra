<?php

namespace App\Listeners;

use App\Models\AgentTrigger;
use App\Models\AiAgent;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadChatSession;
use App\Services\Ai\AiGateway;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentTriggerMessageReceivedListener
{
    use InteractsWithQueue;

    /**
     * Prevent infinite retries on deterministic failures
     */
    public int $tries = 3;

    /**
     * Backoff strategy (seconds)
     */
    public array $backoff = [10, 30, 120];

    public function __construct(
        protected readonly AiGateway $gateway
    ) {}

    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        /**
         * Resolve target model safely
         */
        $target = $event->model ?? null;

        if (! $target || empty($target->tenant_id)) {
            Log::error('[AgentTriggerMessageReceivedListener]: Missing target or tenant_id', [
                'event' => get_class($event),
            ]);

            return;
        }

        /**
         * Get or create chat session
         */
        $this->getSession($target, $event->platform);

        /**
         * Fetch active triggers efficiently
         */
        $triggers = AgentTrigger::query()
            ->select(['id', 'ai_agent_id', 'tenant_id'])
            ->where('tenant_id', $target->tenant_id)
            ->where('event_class', get_class($event))
            ->where('is_active', true)
            ->with([
                'aiAgent:id,slug,is_active',
            ])
            ->get();

        if ($triggers->isEmpty()) {
            Log::error('[AgentTriggerMessageReceivedListener]: No Agent Triggers found for Event: '.get_class($event));

            return; // Silent exit — normal condition
        }

        /**
         * Execute agents safely
         */
        foreach ($triggers as $trigger) {
            $agent = $trigger->aiAgent;

            if (! $agent || ! $agent->is_active) {
                Log::info('[AgentTriggerMessageReceivedListener]: Skipping invalid or inactive Agent: '.$agent->slug);

                continue;
            }

            /* Temporarily set history in $target to pass further */
            $target->chat_history = $this->buildChatHistory($target, $agent, $event->platform);

            try {
                Log::info('[AgentTriggerMessageReceivedListener]: Entering in Ai Gateway for  Agent: '.$agent->slug, [
                    'event' => get_class($event),
                    'target' => $target->getKey() ?? null,
                    'trigger' => $trigger->getKey(),
                ]);
                Cache::lock(
                    "agent_trigger_lock:{$agent->getKey()}:{$target->getKey()}",
                    300
                )->block(0, function () use ($agent, $target) {
                    $this->gateway->executeAgent(
                        $agent->slug,
                        $target
                    );
                });
            } catch (Throwable $e) {
                /**
                 * Do NOT fail the entire listener for one agent
                 */
                Log::error('[AgentTriggerMessageReceivedListener]: Agent execution failed', [
                    'agent' => $agent->slug,
                    'event' => get_class($event),
                    'target' => $target->getKey() ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function getSession(Lead $lead, $platform)
    {
        // Extract the correct ID based on platform logic stored in lead payload
        $platformUserId = $lead->payload['wa_id'] ?? $lead->payload['messenger_id'] ?? $lead->payload['user_id'];

        return LeadChatSession::firstOrCreate(
            [
                'lead_id' => $lead->id,
                'platform' => $platform,
            ],
            [
                'tenant_id' => $lead->tenant_id,
                'platform_user_id' => $platformUserId,
                'last_interaction_at' => now(),
            ]
        );
    }

    protected function buildChatHistory(Lead $lead, AiAgent $agent, $platform)
    {

        // Filter activity by the specific platform to avoid mixing Messenger/WhatsApp chats
        $activities = LeadActivity::where('lead_id', $lead->id)
            // ->where('metadata->platform', $platform) // Ensure your logger saves this
            // ->whereRaw("metadata->>'platform' = ?", [$platform])

            ->whereIn('type', ['message_received', 'ai_reply'])
            ->orderBy('created_at', 'desc')
            ->take($agent->context_window_size ?? 10)
            ->get()
            ->reverse();

        $history = [];

        // Log::error('Activities: ' . json_encode($activities));

        foreach ($activities as $activity) {
            $role = ($activity->type === 'whatsapp_message_sent') ? 'assistant' : 'user';

            // Clean content (remove "SenderName: " prefix if present in your logging)
            $cleanContent = str_replace($lead->payload['full_name'].': ', '', $activity->content);

            $history[] = ['role' => $role, 'content' => $cleanContent];
        }

        return $history;
    }
}
