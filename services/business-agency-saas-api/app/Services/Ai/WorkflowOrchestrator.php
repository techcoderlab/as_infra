<?php

// app/Services/Ai/WorkflowOrchestrator.php

namespace App\Services\Ai;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class WorkflowOrchestrator
{
    /**
     * Decides if the "Context" is allowed to be processed.
     */
    public function canProcess(Tenant $tenant, string $sourceType, array $meta = []): bool
    {
        // 1. Global Switch
        if (! ($tenant->settings->ai_enabled ?? false)) {
            return false;
        }

        // 2. Plan Limits (Check plan_tenant pivot)
        $subscription = DB::table('plan_tenant')
            ->where('tenant_id', $tenant->id)
            // ->where('is_active', true) // Assuming you track active status
            ->first();
        if (! $subscription) {
            return false;
        }

        // 3. Check if current usage is less than the plan limit
        $plan = DB::table('plans')->find($subscription->plan_id);

        return $subscription->ai_credits_used < ($plan->ai_credit_limit ?? 0);
    }

    public function incrementUsage(Tenant $tenant)
    {
        DB::table('plan_tenant')
            ->where('tenant_id', $tenant->id)
            // ->where('is_active', true)
            ->increment('ai_credits_used');
    }

    /**
     * Normalizes 3rd party data into a standard "Agent Payload".
     */
    public function normalizePayload(string $source, array $rawData): array
    {
        return match ($source) {
            'tally' => [
                'context_type' => 'form_submission',
                'description' => 'Submission from Tally Form: '.($rawData['data']['formName'] ?? 'Unknown'),
                'fields' => $this->flattenTallyFields($rawData['data']['fields'] ?? []),
                'raw' => $rawData,
            ],
            'typeform' => [
                'context_type' => 'form_submission',
                'description' => 'Submission from Typeform',
                'fields' => $this->flattenTypeformAnswers($rawData['form_response']['answers'] ?? []),
                'raw' => $rawData,
            ],
            'internal_lead' => [
                'context_type' => 'lead_ingestion',
                'description' => 'Internal CRM Lead',
                'fields' => $rawData,
                'raw' => $rawData,
            ],
            default => [
                'context_type' => 'unknown_webhook',
                'description' => 'Raw Webhook Data',
                'fields' => $rawData,
                'raw' => $rawData,
            ]
        };
    }

    private function flattenTallyFields(array $fields): array
    {
        // Helper to convert complex Tally arrays into Key:Value pairs
        $flat = [];
        foreach ($fields as $field) {
            $flat[$field['label'] ?? $field['key']] = $field['value'];
        }

        return $flat;
    }

    private function flattenTypeformAnswers(array $answers): array
    {
        // Helper for Typeform structure
        $flat = [];
        foreach ($answers as $ans) {
            $type = $ans['type'];
            $flat[$ans['field']['ref'] ?? 'unknown'] = $ans[$type];
        }

        return $flat;
    }
}
