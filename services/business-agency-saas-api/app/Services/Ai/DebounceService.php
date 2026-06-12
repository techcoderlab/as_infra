<?php

namespace App\Services\Ai;

use App\Models\Lead;
use App\Models\LeadChatSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class DebounceService
{
    protected $eventClass;

    // The second argument of app() maps to this constructor
    public function __construct($eventClass)
    {
        $this->eventClass = $eventClass;
    }

    /**
     * Optimized: Zero-latency trigger with Dirty Flag pattern.
     */
    public function trigger(Lead $lead, LeadChatSession $session): void
    {
        $tenantId = $lead->tenant_id;
        $platformId = $session->platform_user_id;
        $sessionKey = "ai_debounce:{$tenantId}:{$platformId}";
        $aggressiveKey = "ai_aggressive_abuse:{$tenantId}:{$platformId}";
        $abuseKey = "ai_abuse:{$tenantId}:{$platformId}";
        $aggressiveWarnedKey = "{$aggressiveKey}:warned";

        // 1. Aggressive Abuse (Burst Protection)
        // Prevents sending more than 5 messages in 10 seconds.
        if (RateLimiter::tooManyAttempts($aggressiveKey, 5)) {
            // Check if we have already sent a warning for this specific limit cycle
            if (! Cache::has($aggressiveWarnedKey)) {
                Log::warning("[DebounceService] Aggressive burst detected for {$platformId}");

                if ((string) $session->platform === 'whatsapp') {
                    $waService = new \App\Services\WhatsAppServiceNative($tenantId);
                    $waService->sendMessage($platformId, "You're sending too fast, please slow down.");

                    // Set a flag that expires when the rate limit decays
                    $secondsRemaining = RateLimiter::availableIn($aggressiveKey);
                    Cache::put($aggressiveWarnedKey, true, $secondsRemaining);
                }
            }

            return;
        }

        // 2. General Abuse (Sustained Protection)
        // Prevents sending more than 20 messages in 1 minute.
        if (RateLimiter::tooManyAttempts($abuseKey, 20)) {
            Log::error("[DebounceService] Sustained rate limit hit for {$platformId}");

            return;
        }

        // Increment both counters
        // Note: The second parameter is the 'decay' time in seconds.
        RateLimiter::hit($aggressiveKey, 10);
        RateLimiter::hit($abuseKey, 60);

        /**
         * 2. DIRTY FLAG PATTERN
         * If we are already processing, just mark as dirty and exit.
         * If not, start processing immediately.
         */
        $processingLock = "{$sessionKey}:processing";
        $dirtyFlag = "{$sessionKey}:dirty";

        if (Cache::has($processingLock)) {
            Log::info("[DebounceService] Session {$platformId} is busy. Marking as DIRTY.");
            Cache::put($dirtyFlag, true, 300);

            return;
        }

        // Start processing
        $this->execute($lead, $session, $sessionKey);
    }

    /**
     * Finalize the session: Release lock and re-trigger if dirty.
     */
    public function finalize(string $sessionKey): void
    {
        $processingLock = "{$sessionKey}:processing";
        $dirtyFlag = "{$sessionKey}:dirty";

        Cache::forget($processingLock);

        if (Cache::pull($dirtyFlag)) {
            Log::info("[DebounceService] Session {$sessionKey} was dirty. Re-triggering AI catch-up.");

            // Re-fetch lead and session to ensure fresh state
            // We parse the sessionKey to get the data if needed, but easier if we just pass them?
            // Actually, finalize is called from the WebhookController which has the Job record.
            // Let's make TriggerAiAgentJobService handle the fetch-and-execute.

            $parts = explode(':', $sessionKey); // ai_debounce:{tenantId}:{platformId}
            if (count($parts) === 3) {
                $tenantId = $parts[1];
                $platformId = $parts[2];

                $session = LeadChatSession::where('tenant_id', $tenantId)
                    ->where('platform_user_id', $platformId)
                    ->first();

                if ($session && $session->lead) {
                    $this->execute($session->lead, $session, $sessionKey);
                }
            }
        }
    }

    /**
     * Internal execution logic.
     */
    protected function execute(Lead $lead, LeadChatSession $session, string $sessionKey): void
    {
        $processingLock = "{$sessionKey}:processing";

        // Lock for 5 minutes (safety timeout)
        Cache::put($processingLock, true, 300);

        Log::info("[DebounceService] Triggering AI immediately for {$session->platform_user_id}");

        // Dispatch the event that starts the AI chain
        if (class_exists($this->eventClass)) {
            event(new $this->eventClass($lead, $session));
        } else {
            Log::error("Attempted to fire non-existent event: {$this->eventClass}");
        }

        // app(\App\Services\TriggerAiAgentJobService::class)->trigger($lead, $session, $sessionKey);
    }

    /**
     * Static helper to generate session key.
     */
    public static function getSessionKey(int $tenantId, string $platformUserId): string
    {
        return "ai_debounce:{$tenantId}:{$platformUserId}";
    }
}
