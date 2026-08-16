<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\Contracts\LlmProviderInterface;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Support\Arr;
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

    ) {
    }

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

        $url = rtrim($this->baseUrl, '/') . '/v1/agent/enqueue';

        $jobUuid = $payload->getTempValue('job_uuid') ?? (string) Str::uuid();


        // 1. FAST CLEANUP & JSON (The Logic we optimized earlier)

        $data = [

            'job_uuid' => $jobUuid,

            'webhook_url' => config('services.mcp_sidecar.webhook_base_url') . '/api/mcp/callback/ai-result',

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

        // if chat_session_data exists in $data['context'] remove it
        // 1. Safe check: Ensure 'chat_session_data' exists without risking direct array access crashes
        if (Arr::has($data, 'context.chat_session_data')) {

            // 2. Safely get the history array (defaults to an empty array if missing)
            $history = Arr::get($data, 'history');

            if (is_array($history) && !empty($history)) {
                // 3. Safely grab the very last element of the array
                $lastItem = Arr::last($history);

                // 4. Extract the content string from that last element
                $lastContent = Arr::get($lastItem, 'content');

                if (is_string($lastContent) && $lastContent !== '') {
                    $currentPrompt = is_string(Arr::get($data, 'userPrompt')) ? $data['userPrompt'] : '';

                    // Pro Tip: Added a space delimiter so strings don't smash together
                    $data['userPrompt'] = ($currentPrompt !== '' ? $currentPrompt . ' ' : '') . '# User ' . $lastContent;
                }

                // Log::info("User Latest Message: ", ['data' => collect($data)->only('userPrompt', 'context', 'history')]);
            }

            // Log::info("McpSidecarAdapter Data Payload: ", ['data' => $data]);
        }



        // Robust & Speedy filtering
        $jsonBody = json_encode(array_filter($data, fn($v) => $v !== null && $v !== '' && $v !== []));

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

                'X-Tenant-ID' => $payload->context['data']['global_data']['tenant_id']
                    ?? $payload->context['tenant_id']
                    ?? null,

                'X-Service-ID' => config('services.mcp_sidecar.calling_api_name'),

            ])

            ->connectTimeout(5) // 5s connection timeout

            ->timeout(10) // 10s total handoff limit

            ->withBody($jsonBody, 'application/json')

            ->post($url);

        // ATTACH THE ERROR HANDLER

        if ($promiseErrorCallback) {

            $promise->otherwise(function ($exception) use ($jobUuid, $promiseErrorCallback) {

                // This runs if the sidecar is down or the request fails

                Log::error("[AI ADAPTER]: Async Handoff Failed for {$jobUuid}: " . $exception->getMessage());

                $promiseErrorCallback($exception);

            });

        }

        /**
         * CRITICAL: Settle the promise.
         * We MUST wait for the promise to settle. If we don't, Guzzle will
         * destroy the cURL handle when the function returns, causing a 
         * ClientDisconnect error on the Python sidecar before it finishes reading.
         * wait(true) tells Guzzle: "Wait for the request, and THROW an exception if it fails
         * so the Laravel Queue can retry it."
         */
        $promise->wait(true);

        Log::info("[AI ADAPTER]: AI Job {$jobUuid} enqueued successfully");

        return [

            'status' => 'queued',

            'job_uuid' => $jobUuid,

        ];

    }
}
