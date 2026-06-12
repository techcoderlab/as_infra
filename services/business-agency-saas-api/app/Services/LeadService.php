<?php

namespace App\Services;

use App\Events\LeadCreated;
use App\Models\Form;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Support\Facades\DB;

class LeadService
{
    /**
     * Process a form submission and create a lead.
     */
    public function processSubmission(Form $form, array $payload, array $metadata = []): Lead
    {
        return DB::transaction(function () use ($form, $payload, $metadata) {
            $sanitizedPayload = sanitize_payload($payload);

            $lead = new Lead([
                'tenant_id' => $form->tenant_id,
                'form_id' => $form->getKey(),
                'source' => 'form',
                'payload' => $sanitizedPayload,
            ]);

            $lead->saveQuietly();

            $activityType = ($form->form_source === 'system')
                ? 'system_form_inserted'
                : 'external_system_form_inserted';

            $activityContent = ($form->form_source === 'system')
                ? "Lead created via public ({$form->name}) form."
                : "Lead created via {$form->form_source} public ({$form->name}) form.";

            LeadActivity::create([
                'lead_id' => $lead->id,
                'type' => $activityType,
                'content' => $activityContent,
                'metadata' => $metadata,
            ]);

            // Dispatch Event (AI Trigger)
            LeadCreated::dispatch($lead);

            // $form->triggerWebhooks($event, [
            //     'data' => [
            //         'id' => $lead->id,
            //         'payload' => $lead->payload ?? [],
            //         'source' => 'form',
            //         'created_timestamp' => $lead->created_at->toIso8601String(),
            //         'form_event' => $event
            //     ],
            // ]);

            return $lead;
        });
    }
}
