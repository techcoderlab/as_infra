<?php

namespace App\Services\Ai\Handlers;

use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Ai\Contracts\WorkflowResultHandler;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Support\Facades\Log;

class ReviewResponseResultHandler implements WorkflowResultHandler
{
    use \App\Services\Ai\Concerns\ResolvesWorkflowTarget;

    public function handle(Tenant $tenant, WorkflowPayload $payload, array $result): void
    {
        $target = $this->resolveTarget($payload);

        if (! $target || ! ($target instanceof Lead)) {
            Log::error('[ReviewResponseResultHandler] Target is not a Lead or not found.');

            return;
        }

        try {
            $rawJson = $result['response'] ?? '{}';
            $parsedData = clean_and_decode_json($rawJson);

            // Expecting: { "reply": "...", "sentiment": "positive|neutral|negative" }
            $reply = $parsedData['reply'] ?? null;
            $sentiment = $parsedData['sentiment'] ?? 'neutral';

            $currentPayload = $target->payload;
            $currentPayload['reply_draft'] = $reply;

            $target->update([
                'status' => 'approved',
                'temperature' => $sentiment,
                'payload' => $currentPayload,
            ]);

            Log::info("[ReviewResponseResultHandler] AI drafted response for review: {$target->id}");
        } catch (\Exception $e) {
            Log::error('[ReviewResponseResultHandler] Failed to process AI result: '.$e->getMessage());
        }
    }
}
