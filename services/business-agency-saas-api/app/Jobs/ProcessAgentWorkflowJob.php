<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ProcessAgentWorkflowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * FIX 0: Set tries to 0 (Unlimited).
     * This ensures that $this->release() (used for rate limiting)
     * does NOT kill the job.
     */
    public $tries = 0;

    /**
     * FIX 1: Remove $tries. Use $maxExceptions instead.
     * $tries counts "release()" as a failure.
     * $maxExceptions only counts actual code crashes.
     */
    public $maxExceptions = 3;

    // Timeout for the job process itself (safety net)
    public $timeout = 120;

    // Wait 10s, then 30s, then 120s between retries
    public $backoff = [10, 30, 120];

    public function __construct(
        protected int $tenantId,
        protected WorkflowPayload $payload
    ) {
    }

    public function handle(LlmProviderInterface $aiProvider)
    {

        // 1. Create the Database record FIRST (The "Memory" of the job)
        /**
         * FIX 2: Consistency in Target Type
         * Ensure we Search AND Save using the same string.
         */
        $targetType = "App\\Models\\{$this->payload->targetType}";

        // We use firstOrCreate so we don't overwrite job_uuid on retries.
        // We will update the status later down.
        $aiJobRecord = AiJob::firstOrCreate(
            [
                'tenant_id' => $this->tenantId,
                'agent_slug' => $this->payload->context['agent_config']['slug'],
                'target_id' => $this->payload->targetId,
                'target_type' => $targetType,
            ],
            [
                'status' => 'pending',
                'payload' => array_merge(
                    $this->payload->toArray(),
                    ['debounce_session_key' => $this->payload->getTempValue('debounce_session_key')]
                ),
                'error_message' => null,
                'job_uuid' => (string) Str::uuid(), // Only set on creation
                'attempts' => 0,
            ]
        );

        // $targetType = 'App\\Models\\' . $this->payload->targetType;

        // $aiJobRecord = AiJob::where('tenant_id', $this->tenantId)
        //     ->where('target_id', $this->payload->targetId)
        //     ->where('target_type', $targetType) // Search using Full Class
        //     ->where('agent_slug', $this->payload->context['agent_config']['slug'])
        //     ->first();

        // $payloadToSave = keys_except($this->payload->toArray(), ['context.agent_config.api_key', 'tool_configs']);

        // if (! $aiJobRecord) {
        //     $aiJobRecord = AiJob::create([
        //         'tenant_id' => $this->tenantId,
        //         'job_uuid' => (string) Str::uuid(),
        //         'target_id' => $this->payload->targetId,
        //         'target_type' => $targetType, // Save using Full Class (Matches Search)
        //         'agent_slug' => $this->payload->context['agent_config']['slug'],
        //         'status' => 'pending',
        //         'payload' => $payloadToSave,
        //         'attempts' => 0,
        //     ]);
        // } else {
        //     // Case B: Retry - If it exists, reset it
        //     $aiJobRecord->update([
        //         'status' => 'pending',
        //         'payload' => $payloadToSave,
        //         'error_message' => null,
        //     ]);
        // }

        /**
         * ------------------------------------------------------
         * SECURITY: JIT CREDENTIAL FETCHING (Zero-Trust Queueing)
         * ------------------------------------------------------
         * Fetch credentials right before execution so they never rest in the Queue.
         */
        $agent = \App\Models\AiAgent::where('tenant_id', $this->tenantId)
            ->where('slug', $this->payload->context['agent_config']['slug'])
            ->first();

        if (!$agent || !$agent->integration?->value['api_key']) {
            $aiJobRecord->update([
                'status' => 'failed',
                'error_message' => 'AI Job failed: Missing Agent or API Key at execution time.',
            ]);
            Log::error('[ProcessAgentWorkflowJob]: Missing Agent/API Key during execution.', ['slug' => $this->payload->context['agent_config']['slug']]);

            return;
        }

        // Inject sensitive data back into the payload dynamically
        $context = $this->payload->context;
        $context['agent_config']['api_key'] = $agent->integration->value['api_key'];

        $history = [];
        if (strtolower($this->payload->targetType) == 'lead' && !empty($agent->context_window_size)) {

            // Reconstruct History from LeadActivity
            // We fetch the last 10 relevant interactions

            $activities = Lead::where('tenant_id', $this->tenantId)->find($this->payload->targetId)->activities()
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

        $this->payload = new WorkflowPayload(
            targetType: class_basename($targetType), // Keep basename
            targetId: $this->payload->targetId,
            context: $context,
            goal: $this->payload->goal,
            requiredTools: $this->payload->requiredTools,
            handlerClass: $this->payload->handlerClass,
            toolConfigs: $agent->tool_configs ?? [], // Inject tool secrets
            tempValueContainer: $this->payload->getTempValue('debounce_session_key')
            ? ['debounce_session_key' => $this->payload->getTempValue('debounce_session_key')]
            : null
        );

        if (!empty($history)) {
            $this->payload->setTempValue('history', $history);
        }

        /**
         * ------------------------------------------------------
         * PRE-FLIGHT CHECK: GLOBAL AI CONCURRENCY GUARD (VPS SAFETY)
         * ------------------------------------------------------
         * Ensures no more than N AI jobs run at once across
         * the entire server, regardless of tenant.
         */
        $globalLimiterKey = 'ai-global-concurrency';
        $maxGlobal = config('services.mcp_sidecar.global_concurrency');
        if (!RateLimiter::attempt($globalLimiterKey, $maxGlobal, fn() => true, 1)) {
            Log::info('[ProcessAgentWorkflowJob]: Global concurrency limit hit, backing off', [
                'tenant' => $this->tenantId,
            ]);

            // Let other processes breathe, retry shortly
            return $this->release(2);
        }

        /**
         * ------------------------------------------------------
         * PRE-FLIGHT CHECK: Per Tenant Rate Limit (N active AI job per tenant)
         * ------------------------------------------------------
         */
        $tenantLimiterKey = "ai-tenant:{$this->tenantId}";
        $tenantConcurrent = config('services.mcp_sidecar.tenant_concurrency');
        if (
            !RateLimiter::attempt(
                $tenantLimiterKey,
                $perSecond = $tenantConcurrent,
                fn() => true,
                $decay = 180 // 3 minutes
            )
        ) {
            Log::info('[ProcessAgentWorkflowJob]: Tenant concurrency limit hit, backing off', [
                'tenant' => $this->tenantId,
            ]);
            // Graceful backoff
            // return $this->release(20);

            // Get the number of times this specific job has been released
            $attempts = $this->attempts();

            // 1st time wait 10s, 2nd time 30s, 3rd+ time 60s
            $delay = match (true) {
                $attempts <= 1 => 10,
                $attempts == 2 => 30,
                default => 60,
            };

            return $this->release($delay);
        }

        /**
         * 2. ATTEMPTS: Increment immediately before trying
         * This helps us detect "Infinite Loop" jobs that fail repeatedly.
         */
        // $aiJobRecord->increment('attempts');

        /**
         * 1. ATOMIC UPDATE (Replaces fetch + increment + update)
         * We update the record and increment 'attempts' in ONE single query.
         * DB::raw('attempts + 1') ensures the increment happens at the database level.
         */
        $aiJobRecord->update([
            'status' => 'processing',
            'attempts' => DB::raw('attempts + 1'),
            'started_at' => now(),
        ]);

        try {
            // Pass UUID to Adapter for the handoff
            $this->payload->setTempValue('job_uuid', $aiJobRecord->job_uuid);

            /**
             * 2. NON-BLOCKING PROCESS
             * Since McpSidecarAdapter now uses Http::async() without .wait(),
             * this call returns almost instantly (approx. 1ms-5ms).
             */
            $aiProvider->process($this->payload, function ($exception) use ($aiJobRecord) {
                // Update the DB record to 'failed' so you know it didn't reach the sidecar
                $aiJobRecord->update([
                    'status' => 'failed',
                    'error_message' => 'AI Job failed: ' . Str::limit($exception->getMessage(), 500),
                ]);
                
                // Throw exception so Laravel Queue handles the retry
                throw $exception;
            });

            Log::info("[ProcessAgentWorkflowJob]: Handoff initiated for {$aiJobRecord->job_uuid}");
        } catch (\Exception $e) {
            // Only hits if there's a structural failure (e.g., payload error)
            $aiJobRecord->update([
                'status' => 'failed',
                'error_message' => 'AI Job failed: ' . Str::limit($e->getMessage(), 500),
            ]);
            throw $e;
        }
    }
}
