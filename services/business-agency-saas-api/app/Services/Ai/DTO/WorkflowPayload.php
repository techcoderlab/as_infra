<?php

namespace App\Services\Ai\DTO;

use App\Models\AiAgent;
use App\Models\LeadChatSession;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

class WorkflowPayload implements Arrayable
{
    /**
     * @param  string  $targetType  Human readable type (e.g., 'lead', 'ticket')
     * @param  int|string  $targetId  Database ID of the entity
     * @param  array  $context  The data the AI analyzes
     * @param  string  $goal  The specific instruction/prompt
     * @param  array  $requiredTools  List of tool keys needed (e.g. ['crm', 'search'])
     * @param  string  $handlerClass  Full namespace of the class that handles the result
     */
    public function __construct(
        public readonly string $targetType,
        public readonly int|string $targetId,
        public readonly array $context,
        public readonly string $goal,
        public readonly array $requiredTools,
        public readonly ?string $handlerClass,
        public readonly array $toolConfigs = [],
        private ?array $tempValueContainer = null
    ) {}

    // --- ADD THIS METHOD ---
    public static function fromArray(array $data): self
    {
        return new self(
            targetType: $data['target_type'] ?? $data['targetType'], // Handle both snake/camel case just in case
            targetId: $data['target_id'] ?? $data['targetId'],
            context: $data['context'] ?? [],
            goal: $data['goal'] ?? '',
            requiredTools: $data['tools'] ?? $data['requiredTools'] ?? [],
            handlerClass: $data['handler_class'] ?? $data['handlerClass'] ?? null,
            toolConfigs: $data['tool_configs'] ?? $data['toolConfigs'] ?? []
        );
    }

    public function toArray(): array
    {
        return [
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'context' => $this->context,
            'goal' => $this->goal,
            'tools' => $this->requiredTools,
            'tool_configs' => $this->toolConfigs,
            'handler_class' => $this->handlerClass,
        ];
    }

    public static function fromAgent(AiAgent $agent, Model $target, ?LeadChatSession $session = null): self
    {
        // PRIVACY FIX: Use toAiContext if available, otherwise fallback to safe defaults or toArray
        $information = method_exists($target, 'toAiContext')
            ? $target->toAiContext()
            : $target->withoutRelations()->toArray();

        $context_addition = [
            'tenant_id' => $target->tenant_id,
            'tenant_name' => $target->tenant->name,
            'target_id' => $target->getKey(),
            'current_date_time' => now()->toDateTimeString(),
        ];

        if ($session) {
            $context_addition['chat_session_id'] = $session->getKey();
            $context_addition['chat_session_platform'] = $session->platform;
            // $context_addition['chat_session_message_count'] = $session->message_count;
            $context_addition['chat_session_status'] = $session->status;
            $context_addition['chat_session_last_interaction'] = $session->last_interaction_at->toDateTimeString();
        }

        /* Uncomment if $target data needs to send in the context as well */
        $context_addition = array_merge($information, $context_addition);

        $goal = '';
        if (! empty($target->payload['text'])) {
            $goal = $agent->hydratePrompt().'User Message: '.$target->payload['text'];
        } else {
            $goal = $agent->hydratePrompt($information);
        }

        return new self(
            targetType: class_basename($target),
            targetId: $target->getKey(),
            context: [
                'data' => $context_addition,
                'agent_config' => [
                    'slug' => $agent->slug,
                    'provider' => $agent->brain,
                    'model' => $agent->model,
                    'system_prompt' => $agent->system_prompt,
                    // SECURITY FIX: api_key is intentionally excluded here to prevent Queue Leakage.
                    // It will be injected Just-In-Time by the ProcessAgentWorkflowJob.
                ],
            ],
            goal: $goal,
            requiredTools: $agent->tools ?? [],
            handlerClass: $agent->handler_class,
            // SECURITY FIX: tool_configs is intentionally excluded here to prevent Queue Leakage.
            toolConfigs: []
        );
    }

    public function setTempValue($key, $value): void
    {
        if (! isset($this->tempValueContainer)) {
            $this->tempValueContainer = [];
        }

        $this->tempValueContainer[$key] = $value;
    }

    public function getTempValue($key): mixed
    {
        if (isset($this->tempValueContainer)) {
            return $this->tempValueContainer[$key] ?? null;
        }

        return null;
    }
}
