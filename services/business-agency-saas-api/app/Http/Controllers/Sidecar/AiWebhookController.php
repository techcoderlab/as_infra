<?php

namespace App\Http\Controllers\Sidecar;

use App\Http\Controllers\Controller;
use App\Models\AiJob;
use App\Services\Ai\DebounceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AiWebhookController extends Controller
{
    /**
     * Handle incoming AI webhook requests.
     *
     * This method verifies the HMAC signature, updates the AI job status,
     * and delegates further processing to the configured handler.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handle(Request $request)
    {
        // 1. SECURITY: Verify HMAC
        if (! $this->isValid($request)) {
            Log::warning('[AiWebhookController] Invalid Signature', [
                'ip' => $request->ip(),
                'headers' => $request->only(['x-timestamp', 'x-signature']),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // 2. PARSE DATA
        // Use Laravel's built-in JSON decoding or fallback manually if content-type is missing.
        $data = $request->isJson() ? $request->all() : json_decode($request->getContent(), true);

        if (empty($data)) {
            Log::warning('[AiWebhookController] Empty or Invalid Payload', ['content_summary' => substr($request->getContent(), 0, 100)]);

            return response()->json(['error' => 'Invalid Payload'], 422);
        }

        $jobUuid = $data['job_uuid'] ?? $data['job_id'] ?? null;
        $status = $data['status'] ?? null;
        $result = $data['data'] ?? null;

        if (! $jobUuid) {
            Log::error('[AiWebhookController] Missing job_uuid in payload');

            return response()->json(['error' => 'Invalid Payload: Missing job_uuid'], 422);
        }

        // 3. FIND JOB
        $job = AiJob::where('job_uuid', $jobUuid)->first();

        if (! $job) {
            Log::error('[AiWebhookController] Job not found: '.$jobUuid);

            return response()->json(['error' => 'Job not found'], 400);
        }

        // 4. IDEMPOTENCY check
        if ($job->status === 'completed') {
            return response()->json(['status' => 'already_completed']);
        }

        if ($status === 'completed') {
            // Handler Execution (Tools/Side Effects)
            $handlerClass = $job->payload['handler_class'] ?? null;

            if ($handlerClass && class_exists($handlerClass)) {
                try {
                    $handler = app($handlerClass);

                    // Rehydrate DTO
                    $payloadDTO = \App\Services\Ai\DTO\WorkflowPayload::fromArray($job->payload);

                    // Execute Handler
                    $handler->handle(
                        $job->tenant,
                        $payloadDTO,
                        $result
                    );
                } catch (\Exception $e) {
                    Log::error('[AiWebhookController] Handler Execution Failed', [
                        'handler' => $handlerClass,
                        'job_uuid' => $jobUuid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Optimize: Use Arr::except for cleaner array manipulation.
        // We remove 'response' because it can be large and is usually processed by the handler.
        // $resultToSave = \Illuminate\Support\Arr::except($result, ['response']);

        $resultToSave = keys_except($result, ['response']);

        // 5. UPDATE DB
        $job->update([
            'status' => $status,
            'result' => $resultToSave,
            'completed_at' => now(),
        ]);

        // 6. Finalize session (Dirty Flag pattern)
        $sessionKey = $job->payload['debounce_session_key'] ?? null;
        if ($sessionKey) {

            $session_platform = $job->payload['context']['data']['chat_session_platform'] ?? null;
            $eventClass = null;

            if ($session_platform === 'whatsapp') {
                $eventClass = \App\Events\WhatsAppMessageReceived::class;
            }

            if ($eventClass) {
                Log::info('[AiWebhookController] Finalizing session '.$sessionKey.' for event '.$eventClass);
                app(DebounceService::class, ['eventClass' => $eventClass])->finalize($sessionKey);
            }
        }

        return response()->json(['status' => 'success']);
    }

    /**
     * Validate the request signature using HMAC SHA256.
     */
    private function isValid(Request $request): bool
    {
        $secret = config('services.mcp_sidecar.token');
        $timestamp = $request->header('X-Timestamp');
        $signature = $request->header('X-Signature');

        return is_valid_signature($secret, $timestamp, $signature, $request->getContent());

    }
}
