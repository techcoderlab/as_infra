<?php

namespace App\Jobs;

use App\Events\WhatsAppMessageReceived;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadChatSession;
use App\Services\Ai\DebounceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $payload;

    protected $tenantId;

    protected const PLATFORM_KEY = 'wa_id';

    protected const PLATFORM = 'whatsapp';

    protected const PLATFORM_LABEL = 'WhatsApp';

    protected const EVENT_CLASS = WhatsAppMessageReceived::class;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $payload, int $tenantId)
    {
        $this->payload = $payload;
        $this->tenantId = $tenantId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('Processing ' . self::PLATFORM_LABEL . " Webhook for tenant {$this->tenantId}");

        try {
            // Flexible Payload Extraction
            $messages = $this->extractMessages($this->payload);

            if (empty($messages)) {
                Log::error('No messages found in ' . self::PLATFORM_LABEL . ' payload.');

                return;
            }

            foreach ($messages as $msg) {
                $this->processMessage($msg);
            }
        } catch (\Exception $e) {
            Log::error('Error processing ' . self::PLATFORM_LABEL . ' webhook: ' . $e->getMessage());
            // We don't want to fail the job hard if it's a parsing error,
            // but for critical failures we might want to retry.
            // For now, logging is sufficient as per 'Graceful error handling'.
        }
    }

    /**
     * Extract messages from the payload regardless of minor structure changes.
     */
    protected function extractMessages(array $payload)
    {
        $extracted = [];

        // Standard Structure: entry[0].changes[0].value.messages
        if (isset($payload['entry']) && is_array($payload['entry'])) {
            foreach ($payload['entry'] as $entry) {
                if (isset($entry['changes']) && is_array($entry['changes'])) {
                    foreach ($entry['changes'] as $change) {
                        if (isset($change['value']['messages']) && is_array($change['value']['messages'])) {
                            // Can add metadata like contacts (names) here too
                            $contacts = $change['value']['contacts'] ?? [];

                            foreach ($change['value']['messages'] as $message) {
                                // Attach contact info if available for this wa_id
                                $senderId = $message['from'] ?? null;
                                $senderProfile = $this->findContactProfile($contacts, $senderId);

                                $message['_sender_profile'] = $senderProfile;
                                $extracted[] = $message;
                            }
                        }
                    }
                }
            }
        }

        return $extracted;
    }

    protected function findContactProfile($contacts, $waId)
    {
        if (! $waId) {
            return null;
        }
        foreach ($contacts as $contact) {
            if (($contact[self::PLATFORM_KEY] ?? '') === $waId) {
                return $contact['profile'] ?? [];
            }
        }

        return null;
    }

    /**
     * Process a single message item.
     */
    protected function processMessage(array $message)
    {
        $waId = $message['from'] ?? null;
        if (! $waId) {
            return;
        }

        // Determine message type and content
        $type = $message['type'] ?? 'unknown';
        $content = null;

        if ($type === 'text') {
            $content = $message['text']['body'] ?? '';
        } else {
            // Handle other types (image, location, etc) simply
            $content = "[{$type} message]";

            return; // return for now, we will handle other types later
        }

        // Find or Create Lead
        // We use the 'payload->wa_id' to uniquely identify the lead for this channel.
        // We also check 'tenant_id'.

        $lead = Lead::where('tenant_id', $this->tenantId)
            ->where('source', self::PLATFORM)
            ->whereRaw('payload->>? = ?', [self::PLATFORM_KEY, $waId])
            ->first();

        // 1. Transaction: Create Lead (if needed) + Log Message + Update Session
        [$lead, $session] = DB::transaction(function () use ($lead, $message, $waId, $type, $content) {
            $activities = [];
            $senderName = $message['_sender_profile']['name'] ?? 'Unknown User';
            $now = now();

            if (! $lead) {
                $lead = new Lead([
                    'tenant_id' => $this->tenantId,
                    'source' => self::PLATFORM,
                    'status' => 'new',
                    'temperature' => 'cold',
                    'payload' => [
                        self::PLATFORM_KEY => $waId,
                        'recipient_phone' => $waId,
                        'full_name' => $senderName,
                        'text' => $content,
                    ],
                ]);
                $lead->saveQuietly();

                $activities[] = [
                    'lead_id' => $lead->id,
                    'type' => 'external_system_inserted', // Use consistent type
                    'content' => 'Lead created from ' . self::PLATFORM_LABEL . " ID {$waId}.",
                    'metadata' => json_encode([
                        'message_id' => $message['id'] ?? null,
                        'timestamp' => $message['timestamp'] ?? time(),
                        'raw_type' => $type,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                Log::info("Created new Lead #{$lead->id} from " . self::PLATFORM_LABEL . " ID {$waId}");
            }

            // Log incoming message activity
            $activities[] = [
                'lead_id' => $lead->id,
                'type' => 'message_received',
                'content' => "{$senderName}: {$content}",
                'metadata' => json_encode([
                    'message_id' => $message['id'] ?? null,
                    'platform' => self::PLATFORM,
                    'timestamp' => $message['timestamp'] ?? time(),
                    'raw_type' => $type,
                    'role' => 'user', // Explicitly mark as user for history reconstruction
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (! empty($activities)) {
                LeadActivity::insert($activities);
            }

            // 2. Manage Chat Session
            // Find active session or create new one
            $session = LeadChatSession::firstOrNew([
                'tenant_id' => $this->tenantId,
                'platform' => self::PLATFORM,
                'platform_user_id' => (string) $waId,
            ]);

            $session->lead_id = $lead->id;
            $session->status = 'active';
            $session->last_interaction_at = $now;
            $session->message_count = ($session->message_count ?? 0) + 1;

            if (!$session->exists) {
                $session->context_data = [];
            }

            $session->save();

            /* DEBOUNCE LOGIC INSTEAD OF DIRECT DISPATCH */
            // WhatsAppMessageReceived::dispatch($lead, $session);
            return [$lead, $session];
        });

        // 3. Trigger AI logic outside of the transaction
        app(DebounceService::class, ['eventClass' => self::EVENT_CLASS])
            ->trigger($lead, $session);

        Log::info('Logged ' . self::PLATFORM_LABEL . " activity for Lead #{$lead->id}");
    }
}
