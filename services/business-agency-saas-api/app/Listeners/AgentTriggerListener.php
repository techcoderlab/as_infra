<?php

namespace App\Listeners;

use App\Models\AgentTrigger;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class AgentTriggerListener
{
    public function __construct(
        protected readonly AiGateway $gateway
    ) {
    }

    public function handle(object $event): void
    {
        /**
         * Resolve target model safely
         */
        $target = $event->model ?? null;

        if (!$target || empty($target->tenant_id)) {
            Log::error('[AgentTriggerListener]: Missing target or tenant_id', [
                'event' => get_class($event),
            ]);

            return;
        }

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
            Log::error('[AgentTriggerListener]: No Agent Triggers found for Event: ' . get_class($event));

            return; // Silent exit — normal condition
        }

        /**
         * Execute agents safely
         */
        foreach ($triggers as $trigger) {
            $agent = $trigger->aiAgent;

            if (!$agent || !$agent->is_active) {
                Log::info('[AgentTriggerListener]: Skipping invalid or inactive Agent: ' . $agent->slug);

                continue;
            }

            try {
                Log::info('[AgentTriggerListener]: Entering in Ai Gateway for  Agent: ' . $agent->slug, [
                    'event' => get_class($event),
                    'target' => $target->getKey() ?? null,
                    'trigger' => $trigger->getKey(),
                ]);
                Cache::lock(
                    "agent_trigger_lock:{$agent->getKey()}:{$target->getKey()}",
                    300
                )->block(0, function () use ($agent, $target, $event) {
                    $this->gateway->executeAgent(
                        $agent->slug,
                        $target,
                        $event->session ?? null
                    );
                });
            } catch (Throwable $e) {
                /**
                 * Do NOT fail the entire listener for one agent
                 */
                Log::error('[AgentTriggerListener]: Agent execution failed', [
                    'agent' => $agent->slug,
                    'event' => get_class($event),
                    'target' => $target->getKey() ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
