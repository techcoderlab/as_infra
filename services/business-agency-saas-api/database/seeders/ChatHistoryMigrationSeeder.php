<?php

namespace Database\Seeders;

use App\Models\AiChat;
use App\Models\ChatMessage;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadChatSession;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatHistoryMigrationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Migrate existing message_received and ai_reply from lead_activities
     * to ai_chats and chat_messages.
     */
    public function run(): void
    {
        $activities = LeadActivity::whereIn('type', ['message_received', 'ai_reply'])
            ->orderBy('id', 'asc')
            ->get();

        $this->command->info("Found {$activities->count()} activities to migrate.");

        DB::beginTransaction();

        try {
            foreach ($activities as $activity) {
                $lead = $activity->lead;
                if (!$lead) continue;

                $metadata = $activity->metadata ?? [];
                
                // Determine platform
                // If it's stored in metadata, use it, otherwise check lead payload
                $platform = $metadata['platform'] ?? 'whatsapp';
                if (!isset($metadata['platform'])) {
                    if (isset($lead->payload['wa_id'])) {
                        $platform = 'whatsapp';
                    } elseif (isset($lead->payload['messenger_id'])) {
                        $platform = 'messenger';
                    }
                }

                // 1. Get or create AiChat
                $aiChat = AiChat::firstOrCreate(
                    [
                        'tenant_id' => $lead->tenant_id,
                        'target_type' => 'lead',
                        'target_id' => $lead->id,
                        'platform' => $platform,
                    ],
                    [
                        'name' => "Chat with {$lead->name}",
                        'status' => 'active',
                        'webhook_url' => '', // Generic since it's lead chat
                    ]
                );

                // 2. Map role and extract clean content
                $role = $activity->type === 'ai_reply' ? 'ai' : 'user';
                $content = $activity->content;

                // For user messages, we sometimes prefixed with "SenderName: " in LeadActivity
                if ($role === 'user' && isset($lead->payload['full_name'])) {
                    $prefix = $lead->payload['full_name'] . ': ';
                    if (str_starts_with($content, $prefix)) {
                        $content = substr($content, strlen($prefix));
                    }
                }

                // 3. Platform Message ID
                $platformMessageId = $metadata['message_id'] ?? null;

                // Prevent duplicate inserts if we re-run the seeder
                $exists = false;
                if ($platformMessageId) {
                    $exists = ChatMessage::where('ai_chat_id', $aiChat->id)
                        ->where('platform_message_id', $platformMessageId)
                        ->exists();
                } else {
                    // Fallback to time + role + content for dedup
                    $exists = ChatMessage::where('ai_chat_id', $aiChat->id)
                        ->where('role', $role)
                        ->where('content', $content)
                        ->where('created_at', $activity->created_at)
                        ->exists();
                }

                if (!$exists) {
                    ChatMessage::create([
                        'ai_chat_id' => $aiChat->id,
                        'user_id' => null, // null for lead messages
                        'role' => $role,
                        'content' => $content,
                        'platform_message_id' => $platformMessageId,
                        'metadata' => $metadata,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->updated_at,
                    ]);
                }

                // 4. Update lead_chat_session to point to this AiChat
                $session = LeadChatSession::where('lead_id', $lead->id)
                    ->where('platform', $platform)
                    ->first();
                
                if ($session && !$session->ai_chat_id) {
                    $session->update(['ai_chat_id' => $aiChat->id]);
                }
            }
            DB::commit();
            $this->command->info("Migration completed successfully.");
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error("Migration failed: " . $e->getMessage());
            Log::error("ChatHistoryMigrationSeeder failed", ['error' => $e->getMessage()]);
        }
    }
}
