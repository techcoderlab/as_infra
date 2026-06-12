<?php

namespace App\Observers;

use App\Events\LeadCreated;
use App\Jobs\DispatchWebhookBatchJob;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Webhook;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class LeadObserver
{
    /**
     * Central trigger method.
     */
    protected function triggerWebhooks(Lead $lead, string $event): void
    {
        if ($lead->suppress_webhooks) {
            return;
        }

        /** ---------------------------------------------
         * If Lead is created by public form, it means form call the attached webhook by it self.
         * --------------------------------------------- */
        if (($event === 'lead.created' && $lead->form)) {
            return;
        }

        /** ---------------------------------------------
         * 1. CIRCUIT BREAKER – LEAD LEVEL
         * --------------------------------------------- */
        $leadKey = "wh:lead:{$lead->id}";

        if (RateLimiter::tooManyAttempts($leadKey, 10)) {
            if (RateLimiter::attempts($leadKey) === 11) {
                Log::warning("⚠ Lead-level loop detected for Lead #{$lead->id}. Webhooks paused.");
                LeadActivity::create([
                    'lead_id' => $lead->id,
                    'type' => 'system_error',
                    'content' => 'Webhooks paused due to loop protection.',
                ]);
            }

            return;
        }
        RateLimiter::hit($leadKey, 60);

        /** ---------------------------------------------
         * 2. CIRCUIT BREAKER – TENANT LEVEL
         * --------------------------------------------- */
        $tenantKey = "wh:tenant:{$lead->tenant_id}";

        if (RateLimiter::tooManyAttempts($tenantKey, 200)) {
            Log::error("🚫 Tenant {$lead->tenant_id} exceeded webhook limit.");

            return;
        }
        RateLimiter::hit($tenantKey, 60);

        /** ---------------------------------------------
         * 3. LOAD WEBHOOKS VIA CACHE
         * --------------------------------------------- */
        $webhooks = Cache::remember(
            "tenant:webhooks:{$lead->tenant_id}",
            60,
            fn () => Webhook::where('tenant_id', $lead->tenant_id)
                ->where('is_active', true)
                ->get()
        );

        /** ---------------------------------------------
         * 4. Filter webhooks for this event
         * --------------------------------------------- */
        $filtered = $webhooks->filter(
            fn ($wh) => in_array($event, $wh->events ?? [])
        );

        if ($filtered->isNotEmpty()) {
            DispatchWebhookBatchJob::dispatch(
                data: Arr::only($lead->toArray(), ['id', 'payload', 'source', 'temperature', 'status', 'meta_data']),
                webhooks: $filtered,
                event: $event
            );
        }

        /** ---------------------------------------------
         * 5. LEGACY FORM WEBHOOK (CREATION ONLY)
         * --------------------------------------------- */
        // if ($event === 'lead.created' && $lead->form && $lead->form->webhook_url) {

        //     $dedupeKey = "legacy_wh_sent_{$lead->id}";
        //     if (!Cache::has($dedupeKey)) {

        //         Cache::put($dedupeKey, true, 10);

        //         $legacy = new Webhook([
        //             'url' => $lead->form->webhook_url,
        //             'secret' => $lead->form->webhook_secret,
        //             'is_active' => true
        //         ]);

        //         DispatchWebhookBatchJob::dispatch(
        //             data: Arr::only($lead->toArray(), ['id','payload','source','temperature','status','meta_data']),
        //             webhooks: collect([$legacy]),
        //             event: "form.submission"
        //         );
        //     }
        // }
    }

    /** ---------------------------------------------
     * MODEL EVENTS
     * --------------------------------------------- */

    /**
     * Handle the Lead "created" event.
     */
    public function created(Lead $lead): void
    {
        // 1. Resolve Activity Context (Robust determination of who/what created this)
        [$type, $content] = $this->determineCreationContext($lead);

        // 2. Create the Audit Log
        LeadActivity::create([
            'lead_id' => $lead->id,
            'type' => $type,
            'content' => $content,
        ]);

        // 3. Dispatch Events
        // Note: Ensure this event is not also dispatching inside your Controller
        // to avoid the "Triple Fire" issue we debugged earlier.

        // LeadCreated::dispatch($lead);

        // 4. Trigger Webhooks
        $this->triggerWebhooks($lead, 'lead.created');
    }

    /**
     * Helper to resolve type and content safely.
     * Returns [string $type, string $content]
     */
    protected function determineCreationContext(Lead $lead): array
    {
        $user = request()->user();

        // --- Scenario A: Authenticated User / API Token ---
        if ($user) {
            // Safe access to custom method 'loggedInFromApp'
            // Assumes index 0 contains boolean true/false for 'isAppToken'
            $isAppToken = method_exists($user, 'loggedInFromApp')
                ? ($user->loggedInFromApp()[0] ?? false)
                : false;

            $method = strtoupper($lead->insert_method ?? 'single');

            return [
                $isAppToken ? 'system_inserted' : 'external_system_inserted',
                "Inserted a lead, using {$method} upload.",
            ];
        }

        // --- Scenario B: Public Form Submission ---
        // Check form relation existence or source string
        // if ($lead->source === 'form' || $lead->form_id) {
        //     $formName = $lead->form ? "({$lead->form->name}) form" : 'external form';

        //     return [
        //         $lead->form_id ? 'system_form_inserted' : 'external_system_form_inserted',
        //         "Lead created via public {$formName}."
        //     ];
        // }

        // --- Scenario C: Fallback / Console / Seeder ---
        // Prevents "Undefined Variable" errors if created via Artisan or unexpected source
        return [
            'system_inserted',
            'Inserted a lead, using UNKNOWN upload.',
        ];
    }

    public function updated(Lead $lead)
    {
        // 1. Lightweight Check: Avoid crashing if running from CLI/Queue (no request)
        $user = request()->user();
        $isAppToken = $user && method_exists($user, 'loggedInFromApp') ? $user->loggedInFromApp()[0] : false;

        // Pre-calculate static strings to save CPU cycles inside loops
        // $actorLabel = $isAppToken ? 'System' : 'External system';
        $typePrefix = $isAppToken ? '' : 'external_';

        $activities = [];
        $events = [];

        // 2. Use 'wasChanged' instead of 'isDirty'
        // 'isDirty' is often empty inside the 'updated' event because data is already synced.
        // 'wasChanged' is reliable here.

        if ($lead->wasChanged('status')) {
            $activities[] = [
                'type' => "{$typePrefix}system_updated_status",
                'content' => "Moved this lead from '{$lead->getOriginal('status')}' to '{$lead->status}' pipeline.",
            ];
            $events[] = 'lead.updated.status';
        }

        if ($lead->wasChanged('temperature')) {
            $activities[] = [
                'type' => "{$typePrefix}system_updated_temperature",
                'content' => "Updated temperature of this lead from '{$lead->getOriginal('temperature')}' to '{$lead->temperature}' pipeline.",
            ];
            $events[] = 'lead.updated.temperature';
        }

        if (! empty($activities)) {
            $now = now();

            // 3. Native Arrays over Collections
            // On 1.5GB RAM, creating Collection objects uses unnecessary memory.
            // Standard PHP loops are faster and lighter.
            foreach ($activities as &$activity) {
                $activity['lead_id'] = $lead->id;
                $activity['created_at'] = $now;
                $activity['updated_at'] = $now;
            }
            unset($activity); // Break reference

            // 4. Single Database Hit
            LeadActivity::insert($activities);

            $events[] = 'lead.updated';

            foreach (array_unique($events) as $event) {
                $this->triggerWebhooks($lead, $event);
            }
        }
    }
}

// namespace App\Observers;

// use App\Models\Lead;
// use App\Models\LeadActivity;
// use App\Models\Webhook;
// use App\Jobs\DispatchWebhookJob;
// use Illuminate\Support\Facades\RateLimiter; // Import this
// use Illuminate\Support\Facades\Log;

// class LeadObserver
// {
//     /**
//      * Helper to dispatch webhooks with Circuit Breaker Protection
//      */
//     protected function triggerWebhooks(Lead $lead, string $event): void
//     {
//         // 1. Check Manual Suppression (Optional safety)
//         if ($lead->suppress_webhooks) {
//             return;
//         }

//         // 2. CIRCUIT BREAKER (The Real Safety Net)
//         // We track attempts by a unique key per lead: "webhook:lead:{ID}"
//         // Limit: 10 webhooks per 60 seconds.
//         $key = "webhook:lead:{$lead->id}";

//         if (RateLimiter::tooManyAttempts($key, 10)) {
//             // Loop Detected! We stop here.
//             // We only log it once per minute to avoid flooding logs too.
//             if (RateLimiter::attempts($key) === 11) {
//                 Log::warning("⚠️ Infinite Loop Detected for Lead #{$lead->id}. Webhooks temporarily paused.");

//                 // Optional: Create a system note so the admin sees why it stopped
//                 LeadActivity::create([
//                     'lead_id' => $lead->id,
//                     'type' => 'system',
//                     'content' => "Paused webhooks due to high activity (Loop Protection)."
//                 ]);
//             }
//             return;
//         }

//         // Count this attempt
//         RateLimiter::hit($key, 60); // 60 seconds decay

//         // --- PROCEED WITH NORMAL DISPATCH ---

//         // 3. Fire Global Tenant Webhooks
//         $webhooks = Webhook::where('tenant_id', $lead->tenant_id)
//             ->where('is_active', true)
//             ->get();

//         foreach ($webhooks as $webhook) {
//             // Check if this webhook subscribes to this event
//             if (in_array($event, $webhook->events ?? [])) {
//                 DispatchWebhookJob::dispatch($lead, $webhook, $event);
//             }
//         }

//         // 4. Fire Legacy Form Webhook (Only on Creation)
//         if ($event === 'lead.created' && $lead->form && $lead->form->webhook_url) {
//             $legacyWebhook = new Webhook([
//                 'url' => $lead->form->webhook_url,
//                 'secret' => $lead->form->webhook_secret,
//                 'is_active' => true
//             ]);
//             DispatchWebhookJob::dispatch($lead, $legacyWebhook, 'form.submission');
//         }
//     }

//     public function created(Lead $lead): void
//     {
//         LeadActivity::create([
//             'lead_id' => $lead->id,
//             'type' => 'system',
//             'content' => "Lead created via {$lead->source}"
//         ]);

//         $this->triggerWebhooks($lead, 'lead.created');
//     }

//     public function updated(Lead $lead): void
//     {
//         $changes = [];
//         $eventsToFire = [];

//         if ($lead->isDirty('status')) {
//             $changes[] = "Status changed from '{$lead->getOriginal('status')}' to '{$lead->status}'";
//             $eventsToFire[] = 'lead.updated.status';
//         }

//         if ($lead->isDirty('temperature')) {
//             $changes[] = "Temperature changed from '{$lead->getOriginal('temperature')}' to '{$lead->temperature}'";
//             $eventsToFire[] = 'lead.updated.temperature';
//         }

//         if (!empty($changes)) {
//             foreach ($changes as $change) {
//                 LeadActivity::create([
//                     'lead_id' => $lead->id,
//                     'type' => 'status_change',
//                     'content' => $change
//                 ]);
//             }

//             $eventsToFire[] = 'lead.updated';

//             // Fire events (unique)
//             foreach (array_unique($eventsToFire) as $event) {
//                 $this->triggerWebhooks($lead, $event);
//             }
//         }
//     }
// }
