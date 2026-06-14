<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class McpSidecarAdapter implements LlmProviderInterface
{
    // Reduced timeout: We only care about the handoff.

    private $requestTimeout = 2;

    public function __construct(

        protected string $baseUrl,

        protected string $client_app_id,

        protected string $client_secret

    ) {}

    public function process(WorkflowPayload $payload, $promiseErrorCallback = null): array
    {

        return $this->processAgentEnqueue($payload, $promiseErrorCallback);

    }

    public function supportsStreaming(): bool
    {

        return false;

    }

    private function processAgentEnqueue($payload, $promiseErrorCallback = null)
    {

        $timestamp = time();

        $url = rtrim($this->baseUrl, '/').'/v1/agent/enqueue';

        $jobUuid = $payload->getTempValue('job_uuid') ?? (string) Str::uuid();


        // 1. FAST CLEANUP & JSON (The Logic we optimized earlier)

        $data = [

            'job_uuid' => $jobUuid,

            'webhook_url' => config('services.mcp_sidecar.webhook_base_url').'/api/mcp/callback/ai-result',

            'provider' => $payload->context['agent_config']['provider'],

            'apiKey' => $payload->context['agent_config']['api_key'],

            'model' => $payload->context['agent_config']['model'],

            'systemPrompt' => $payload->context['agent_config']['system_prompt'],

            'userPrompt' => $payload->goal,

            'context' => $payload->context['data'],

            'tools' => $payload->requiredTools,

            'tool_configs' => $payload->toolConfigs,

            'history' => $payload->getTempValue('history') ?? [],

            'thinking_budget' => -1,

            'use_stream' => $this->supportsStreaming(),

        ];

        // Robust & Speedy filtering

        $jsonBody = json_encode(array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []));

        // $signature = hash_hmac('sha256', $timestamp . $jsonBody, $this->client_secret);

        $appId = $this->client_app_id;
        $secret = $this->client_secret;

        $signature = create_valid_signature($secret, $timestamp, $jsonBody);

        // 2. TRUE ASYNC HANDOFF (No .wait())

        $promise = Http::async()

            ->withHeaders([

                'X-App-Id' => $appId,

                'X-Signature' => $signature,

                'X-Timestamp' => $timestamp,

                'X-Tenant-ID' => $payload->context['tenant_id'] ?? null,

                'X-Service-ID' => config('services.mcp_sidecar.calling_api_name'),

            ])

            ->connectTimeout(2) // Aggressive 2s connection timeout

            ->timeout(3) // 3s total handoff limit

            ->withBody($jsonBody, 'application/json')

            ->post($url);

        // ATTACH THE ERROR HANDLER

        if ($promiseErrorCallback) {

            $promise->otherwise(function ($exception) use ($jobUuid, $promiseErrorCallback) {

                // This runs if the sidecar is down or the request fails

                Log::error("[AI ADAPTER]: Async Handoff Failed for {$jobUuid}: ".$exception->getMessage());

                $promiseErrorCallback($exception);

            });

            /**
             * CRITICAL: Settle the promise.

             * wait(false) tells Guzzle: "Start sending now, but don't throw an

             * exception here if it fails; let the .catch() handle it."
             */
            $promise->wait(false);

        }

        Log::info("[AI ADAPTER]: AI Job {$jobUuid} enqueued successfully");

        return [

            'status' => 'queued',

            'job_uuid' => $jobUuid,

        ];

    }
}
