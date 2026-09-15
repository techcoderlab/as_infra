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

        $useMemoryGraph = $payload->context['agent_config']['use_memory_graph'] ?? false;
        // 1. FAST CLEANUP & DATA CONSTRUCTION
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
            'max_iterations' => $payload->context['agent_config']['provider'] === 'cloudflare' ? 15 : 7,
            'use_memory_graph' => $useMemoryGraph,
        ];

        // // Safely merge global_data
        // $globalData = $payload->context['data']['global_data'] ?? null;
        // if (is_array($globalData)) {
        //     $data = array_merge($data, $globalData);
        // }

        // 2. OPTIMIZED CHAT SESSION LOGIC
        if (isset($data['context']['chat_session_data'])) {
            $history = $data['history'];

            if (!empty($history) && is_array($history)) {
                // Native end() is faster than Arr::last()
                $lastItem = end($history);
                $lastContent = $lastItem['content'] ?? '';

                if (is_string($lastContent) && $lastContent !== '') {
                    $currentPrompt = $data['userPrompt'] ?? '';
                    $data['userPrompt'] = trim($currentPrompt . ' # User ' . $lastContent);
                }
            }

            // CRITICAL FIX: Actually remove it to reduce network payload size
            unset($data['context']['chat_session_data']);
        }

        // // if chat_session_data exists in $data['context'] remove it
        // // 1. Safe check: Ensure 'chat_session_data' exists without risking direct array access crashes
        // if (Arr::has($data, 'context.chat_session_data')) {

        //     // 2. Safely get the history array (defaults to an empty array if missing)
        //     $history = Arr::get($data, 'history');

        //     if (is_array($history) && !empty($history)) {
        //         // 3. Safely grab the very last element of the array
        //         $lastItem = Arr::last($history);

        //         // 4. Extract the content string from that last element
        //         $lastContent = Arr::get($lastItem, 'content');

        //         if (is_string($lastContent) && $lastContent !== '') {
        //             $currentPrompt = is_string(Arr::get($data, 'userPrompt')) ? $data['userPrompt'] : '';

        //             // Pro Tip: Added a space delimiter so strings don't smash together
        //             $data['userPrompt'] = ($currentPrompt !== '' ? $currentPrompt . ' ' : '') . '# User ' . $lastContent;
        //         }

        //         // Log::info("User Latest Message: ", ['data' => collect($data)->only('userPrompt', 'context', 'history')]);
        //     }

        //     // Log::info("McpSidecarAdapter Data Payload: ", ['data' => $data]);
        // }
        /* ---------------------------------------- */

        // 3. ENCODE & SIGN
        $jsonBody = json_encode(array_filter($data, fn($v) => $v !== null && $v !== '' && $v !== []));

        $signature = create_valid_signature($this->client_secret, $timestamp, $jsonBody);

        // 4. SYNCHRONOUS HANDOFF (Since you were waiting on the promise anyway)
        $promise = Http::async()

            ->withHeaders([

                'X-App-Id' => $this->client_app_id,

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
        try {
            $promise->wait(true);
        } catch (\Throwable $e) {
            $message = "Sidecar connection failed: " . $e->getMessage();

            if (method_exists($e, 'getResponse') && $e->getResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                if (!empty($responseBody)) {
                    $message .= " | Response: " . Str::limit($responseBody, 300);
                }
            }

            throw new \App\Exceptions\SidecarUnreachableException($message, $e->getCode(), $e);
        }

        Log::info("[AI ADAPTER]: AI Job {$jobUuid} enqueued successfully");

        return [

            'status' => 'queued',

            'job_uuid' => $jobUuid,

        ];

    }
}
