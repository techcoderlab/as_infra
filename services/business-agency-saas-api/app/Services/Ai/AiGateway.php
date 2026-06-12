<?php

namespace App\Services\Ai;

use App\Jobs\ProcessAgentWorkflowJob;
use App\Models\AiAgent;
use App\Models\LeadChatSession;
use App\Models\Tenant;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiGateway
{
    /**
     * Execute a specific named Agent against a target model.
     */
    /* nullable session */
    public function executeAgent(string $slug, Model $target, ?LeadChatSession $session = null): void
    {
        $tenantId = $target->tenant_id;

        if (! $tenantId) {
            Log::error("[AI GATEWAY]: AI Agent '{$slug}' | Missing tenant_id for target MODEL.");

            return;
        }

        /**
         * Cache agent configuration (VERY IMPORTANT)
         */
        $agent = Cache::remember(
            "ai_agent:{$tenantId}:{$slug}",
            600,
            fn () => AiAgent::query()
                ->where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->where('is_active', true)
                // ->with('tenant') // Eager load if needed
                ->first()
        );

        if (! $agent) {
            Log::error("[AI GATEWAY]: AI Agent '{$slug}' not found or not active for Tenant {$tenantId}.");

            return;
        }

        if (! $agent->integration?->value['api_key']) {
            Log::error("[AI GATEWAY]: AI Agent '{$slug}' | Missing API key for brain '{$agent->brain}'.");

            return;
        }

        /**
         * Cheap limit check (final check happens in job)
         */
        // if (!Tenant::canTenantUseAiCached($tenantId)) {
        //     return;
        // }

        Log::info('[AI GATEWAY]: Dispatching ProcessAgentWorkflowJob for Agent: '.$agent->slug, [
            'tenant_id' => $tenantId,
            'target' => class_basename($target),
        ]);

        // PRIVACY FIX: Use toAiContext if available, otherwise fallback to safe defaults or toArray
        // $contextData = method_exists($target, 'toAiContext')
        //     ? $target->toAiContext()
        //     : $target->toArray();

        // INJECTION DEFENSE LAYER 1:
        // We pass the RAW data to the context (safe), but the Prompt Hydration happens in the Payload via Agent.

        $history = [];
        if ($target instanceof \App\Models\Lead && ! empty($agent->context_window_size)) {

            // Reconstruct History from LeadActivity
            // We fetch the last 10 relevant interactions
            $activities = $target->activities()
                ->whereIn('type', ['message_received', 'ai_reply'])
                ->latest()
                ->take((int) $agent->context_window_size)
                ->get()
                ->reverse(); // Chronological order for LLM

            foreach ($activities as $activity) {
                // If the activity is a user message
                if ($activity->type === 'message_received') {
                    // Extract content cleanly (remove sender name prefix if present)
                    // Format: "Name: message" -> we just want "message" if possible, or keep as is.
                    // For now, simpler is better:
                    $history[] = ['role' => 'user', 'content' => $activity->content];
                }
                // If the activity is an AI response
                elseif ($activity->type === 'ai_reply') {
                    $history[] = ['role' => 'assistant', 'content' => $activity->content];
                }
            }
        }

        // Add history to context so the Job can pass it to the Python sidecar
        $contextPayload = WorkflowPayload::fromAgent(
            agent: $agent,
            target: $target,
            session: $session
        );

        $contextPayload->setTempValue('history', $history);

        // Add session key for debouncing finalization
        if ($session) {
            $sessionKey = \App\Services\Ai\DebounceService::getSessionKey($tenantId, $session->platform_user_id);
            $contextPayload->setTempValue('debounce_session_key', $sessionKey);
        }

        ProcessAgentWorkflowJob::dispatch(
            tenantId: $tenantId,
            payload: $contextPayload
        )->onQueue('ai-heavy');
    }

    /**
     * Stream a chat session to the Python Sidecar.
     *
     * @param  AiAgent  $agent  The loaded agent model (with tools/prompt)
     * @param  array  $context  Data context (Lead ID, User Name, etc.)
     * @param  array  $history  Structured history [['role' => 'user', 'content' => '...']]
     * @param  string  $userMessage  The current user text
     * @return \Illuminate\Http\Client\Response
     */
    public function streamChat(AiAgent $agent, array $context, array $history, string $userMessage)
    {
        // 1. Resolve API Key
        // If you haven't built the 'integrations' table yet, use this fallback:
        $apiKey = $agent->integration?->value['api_key'];

        if (empty($apiKey)) {
            throw new \Exception("No API Key found for provider: {$agent->brain}");
        }

        $timestamp = time();
        $url = config('services.mcp_sidecar.url').'/v1/agent/chat';

        // 2. Build Payload -> OPTIMIZATION: Send 'history' as an array. The Python sidecar will map it to the native Gemini/OpenAI message format.
        $data = [
            'provider' => $agent->brain,
            'apiKey' => $apiKey,
            'model' => $agent->model,
            'systemPrompt' => $agent->system_prompt,
            // Conversation State
            'history' => $history,     // Pass the array directly
            'userPrompt' => $userMessage,
            // Capabilities
            'context' => $context,
            'tools' => $agent->tools ?? [], // e.g. ['read_leads', 'update_lead']
            'tool_configs' => $agent->tool_configs ?? [], // e.g. ['read_leads' => ['api_key' => '...']]
        ];

        $jsonBody = json_encode($data);

        // SIGNING STRING: Timestamp + Raw JSON Body
        // This proves the timestamp is real AND the body wasn't changed.
        $signature = hash_hmac('sha256', $timestamp.$jsonBody, config('services.mcp_sidecar.token'));

        // 3. Send Stream Request
        return Http::withOptions([
            'stream' => true,
        ])->withHeaders([
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
            'X-Tenant-ID' => $context['tenant_id'] ?? null,
            'X-Service-ID' => config('services.mcp_sidecar.calling_api_name'),
            'Content-Type' => 'application/json',
            'Accept' => 'text/event-stream', // Important for SSE
        ])
            ->timeout(120)
            ->withBody($jsonBody, 'application/json')
            ->post($url);
    }

    // /**
    //  * NEW: Stream an interactive chat directly to the Sidecar.
    //  *
    //  * @param  array  $context  The full data context (Lead, User, etc.)
    //  * @param  array  $history  Last 10 messages [['role' => 'user', 'content' => '...']]
    //  * @param  string  $userMessage  The current user input
    //  * @param  Closure  $onChunk  Callback to handle incoming chunks (optional)
    //  * @return \Illuminate\Http\Client\Response
    //  */
    // public function streamChat(AiAgent $agent, array $context, array $history, string $userMessage)
    // {
    //     $integration = $agent->integration;
    //     if (! $integration || empty($integration->value['api_key'])) {
    //         throw new \Exception('Agent has no API Key configured.');
    //     }

    //     $url = config('services.mcp_sidecar.url') . '/v1/agent/chat';

    //     // Prepare the specific payload for the Chat Endpoint
    //     // We merge history into the system prompt or manage it via the sidecar
    //     // Ideally, we send the history separately if the sidecar supports it,
    //     // but for now, we'll append it to the "userPrompt" to keep the sidecar stateless.

    //     $payload = [
    //         'provider' => $agent->brain, // 'openai' or 'gemini'
    //         'apiKey' => $integration->value['api_key'],
    //         'model' => $agent->model,
    //         'systemPrompt' => $agent->system_prompt,
    //         // We combine history + new message here so the stateless sidecar "remembers"
    //         'userPrompt' => $this->formatHistory($history, $userMessage),
    //         'context' => $context,
    //         'tools' => $agent->tools ?? [],
    //     ];

    //     // We return the raw stream options so the Controller can pipe it
    //     return Http::withOptions([
    //         'stream' => true,
    //         'timeout' => 120, // Allow long "thinking" time
    //     ])->withHeaders([
    //         'x-service-token' => config('services.mcp_sidecar.token'),
    //         'Content-Type' => 'application/json',
    //     ])->post($url, $payload);
    // }
    // protected function formatHistory(array $history, string $newMessage): string
    // {
    //     // Simple formatting to help the LLM understand conversation flow
    //     $formatted = "PREVIOUS CHAT HISTORY:\n";
    //     foreach ($history as $msg) {
    //         $role = strtoupper($msg['role']);
    //         $formatted .= "{$role}: {$msg['content']}\n";
    //     }
    //     $formatted .= "\nCURRENT USER REQUEST:\n{$newMessage}";

    //     return $formatted;
    // }

    // public function executeAgent(string $slug, Model $target): void
    // {
    //     // 1. Resolve Tenant
    //     $tenant = $target->tenant ?? Tenant::find($target->tenant_id);

    //     if (!$tenant) {
    //         Log::warning("AI Agent '{$slug}' skipped: Target has no Tenant.");
    //         return;
    //     }

    //     // 2. Fetch Agent Configuration
    //     $agent = AiAgent::where('tenant_id', $tenant->id)
    //         ->where('slug', $slug)
    //         ->where('is_active', true)
    //         ->with('tenant') // Eager load if needed
    //         ->first();

    //     if (!$agent) {
    //         Log::info("AI Agent '{$slug}' not found or inactive for Tenant {$tenant->id}.");
    //         return;
    //     }

    //     // 3. Resolve Integration (Brain/API Key)
    //     // Using the accessor we created in the Model: $agent->integration
    //     $integration = $agent->integration;

    //     if (!$integration || empty($integration->value['api_key'])) {
    //         Log::error("AI Agent '{$slug}' failed: No API Key found for brain '{$agent->brain}'.");
    //         return;
    //     }

    //     // 4. Check Credit Limits
    //     if (!$tenant->canTenantUseAi()) {
    //         Log::info("AI Execution skipped: Tenant {$tenant->id} limit reached.");
    //         return;
    //     }

    //     $targetModelData = $target->toArray();

    //     // 5. Hydrate User Prompt (Replace Placeholders)
    //     $hydratedPrompt = $agent->hydratePrompt($targetModelData);

    //     // 6. Construct Payload
    //     // We inject the Agent's specific config into the Custom Context so the Job knows what to do
    //     $context = [
    //         'data' => $targetModelData,
    //         'agent_config' => [
    //             'model' => $agent->model,
    //             'system_prompt' => $agent->system_prompt,
    //             'api_key' => $integration->value['api_key'], // Decrypted via cast
    //             'provider' => $agent->brain, // e.g., 'openai'
    //         ]
    //     ];

    //     $payload = new WorkflowPayload(
    //         targetType: class_basename($target),
    //         targetId: $target->getKey(),
    //         context: $context,
    //         goal: $hydratedPrompt,
    //         requiredTools: $agent->tools ?? [],
    //         handlerClass: $agent->handler_class
    //     );

    //     // 7. Dispatch Job
    //     ProcessAgentWorkflowJob::dispatch($tenant, $payload);
    // }
}
