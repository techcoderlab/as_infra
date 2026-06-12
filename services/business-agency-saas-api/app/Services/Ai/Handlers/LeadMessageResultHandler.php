<?php

namespace App\Services\Ai\Handlers;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Tenant;
use App\Services\Ai\Contracts\WorkflowResultHandler;
use App\Services\Ai\DTO\WorkflowPayload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LeadMessageResultHandler implements WorkflowResultHandler
{
    use \App\Services\Ai\Concerns\ResolvesWorkflowTarget;

    /**
     * Handle the result of a Lead Message AI workflow.
     */
    public function handle(Tenant $tenant, WorkflowPayload $payload, array $result): void
    {
        // 1. Resolve Target
        $target = $this->resolveTarget($payload);

        if (! $target) {
            return;
        }

        if (! ($target instanceof Lead)) {
            Log::error('[LeadMessageResultHandler]: Target is not a Lead.', ['target_type' => get_class($target)]);

            return;
        }

        try {
            // 2. Parse AI Response
            $rawJson = $result['response'] ?? '{}';

            // Assuming clean_and_decode_json is a robust global helper available in the project
            $parsedData = clean_and_decode_json($rawJson);
            $thoughtStream = $result['thoughtStream'] ?? [];

            // Fallback reply if empty
            $reply = $parsedData['reply'] ?? null;
            $responseText = ! empty($reply)
                ? $reply
                : 'I’m so sorry, I didn’t quite catch that. Could you rephrase that or say it again?';

            // 3. Send WhatsApp Message
            // We use our dedicated WhatsAppService
            $waService = new \App\Services\WhatsAppServiceNative($target->tenant_id);

            // Use phone from payload, fallback to target phone
            $recipientPhone = $target->payload['recipient_phone'] ?? $target->payload['phone'];
            if ($recipientPhone) {

                // Check if Session exists
                $session = \App\Models\LeadChatSession::where('lead_id', $target->getKey())->first();
                if (! $session) {
                    Log::warning('[LeadMessageResultHandler]: Lead Chat Session not found', ['lead_id' => $target->getKey()]);

                    return;
                }

                // Send standard text
                $waService->sendMessage($recipientPhone, $responseText);

                // Update Session
                $now = now();
                $session->update([
                    'message_count' => DB::raw('message_count + 1'),
                    'last_interaction_at' => $now,
                ]);

                // Update activity status to 'sent'
                $this->logActivity($target, $parsedData, $thoughtStream, $responseText, $now);
            }
        } catch (\Exception $e) {
            Log::error('[LeadMessageResultHandler] Execution Failed', [
                'error' => $e->getMessage(),
                'lead_id' => $target->id,
            ]);
        }
    }

    /**
     * Create a human-readable audit log of the Agent's actions.
     */
    protected function logActivity(Model $target, array $parsedData, array $thoughtStream, string $actualReply, $now = null): void
    {

        // We use bulk insert for performance
        $activity = [
            'lead_id' => $target->id,
            'type' => 'ai_reply',
            'content' => $actualReply,
            'metadata' => json_encode([
                'role' => 'assistant',
                'responseData' => $parsedData ?? [],
                'thoughtStream' => $thoughtStream ?? [],
            ]),
            'created_at' => $now ?? now(),
            'updated_at' => $now ?? now(),
        ];

        LeadActivity::insert([$activity]);
    }
}
