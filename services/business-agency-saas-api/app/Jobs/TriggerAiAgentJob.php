<?php

namespace App\Jobs;

use App\Events\WhatsAppMessageReceived;
use App\Models\Lead;
use App\Models\LeadChatSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TriggerAiAgentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $lead;

    public $session;

    public $sessionKey;

    // Use a small timeout for the job itself
    public $timeout = 30;

    public function __construct(Lead $lead, LeadChatSession $session, string $sessionKey)
    {
        $this->lead = $lead;
        $this->session = $session;
        $this->sessionKey = $sessionKey;
    }

    public function handle()
    {
        /**
         * 1. MINI-BUFFER (The "Human Typing" Pause)
         * Instead of releasing back to queue (which adds latencies),
         * we sleep for 1.5s to allow multi-part messages to land in the DB.
         */
        usleep(500000);

        /**
         * 2. ATOMIC EXECUTION CHECK
         * We ensure that even if two jobs were dispatched, only one proceeds.
         */
        $execLock = "{$this->sessionKey}:executing";
        if (! Cache::add($execLock, true, 20)) {
            return; // Exit if another job is already processing this session
        }

        try {
            Log::info("[TriggerAiAgentJob] Buffer finished. Triggering AI for {$this->session->platform_user_id}");

            /**
             * 3. DISPATCH TO AI
             * Crucial: Your Listener/Gateway must fetch the LATEST messages
             * from the DB so it sees all messages arrived during the 1.5s sleep.
             */
            WhatsAppMessageReceived::dispatch($this->lead, $this->session);
        } finally {
            // 4. CLEANUP
            // We keep the 'active_processing' lock from DebounceService for a bit
            // to prevent immediate re-triggering while AI is thinking.
            $this->clearDebounceKeys();
        }
    }

    protected function clearDebounceKeys()
    {
        Cache::forget("{$this->sessionKey}:job_pending");
        Cache::forget("{$this->sessionKey}:last_message_at");
        Cache::forget("{$this->sessionKey}:first_message_at");
        // Note: We do NOT forget the 'executing' lock yet;
        // let it expire naturally to provide a "cool-down" period.
    }
}
