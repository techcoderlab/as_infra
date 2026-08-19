<?php

// ─────────────────────────────────────────────────────
// Module   : AiJobController
// ─────────────────────────────────────────────────────

namespace App\Http\Controllers\Sidecar;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AiJobController extends Controller
{
    /**
     * Monitor the latest AI job for a given lead target.
     *
     * Enforces tenant ownership: a user can only poll job status
     * for leads that belong to their active tenant (P2 - Authorization).
     *
     * @param  Request  $request
     * @param  int|string  $target_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function monitor(Request $request, $target_id)
    {
        $user = Auth::user();

        // P2: Scope the lead lookup to the authenticated user's active tenant.
        // This prevents Tenant A from polling job status for Tenant B's leads.
        $lead = Lead::where('tenant_id', $user->current_tenant_id)
            ->find($target_id);

        $job = $lead ? $lead->latestJob()->first() : null;

        if (!$job) {
            return response()->json([
                'message' => 'Job not found',
            ], 404);
        }

        return response()->json([
            'status' => $job->status,
            'completed_at' => $job->completed_at,
            'attempts' => $job->attempts,
        ]);
    }
    public function retry(Request $request, $target_id)
    {
        $user = Auth::user();

        // P2: Scope the lead lookup to the authenticated user's active tenant.
        $lead = Lead::where('tenant_id', $user->current_tenant_id)
            ->find($target_id);

        $job = $lead ? $lead->latestJob()->first() : null;

        if (!$job) {
            return response()->json([
                'message' => 'Job not found',
            ], 404);
        }

        if ($job->status !== 'failed') {
            return response()->json([
                'message' => 'Only failed jobs can be retried.',
            ], 400);
        }

        // Reconstruct WorkflowPayload and Dispatch
        // $payload = \App\Services\Ai\DTO\WorkflowPayload::fromArray($job->payload);
        $payload = \App\Services\Ai\DTO\WorkflowPayload::fromAgent(
            \App\Models\AiAgent::where('tenant_id', $lead->tenant_id)->where('slug', $job->agent_slug)->first(),
            $lead
        );
        
        $payload->setTempValue('job_uuid', $job->job_uuid);

        if (isset($job->payload['debounce_session_key'])) {
            $payload->setTempValue('debounce_session_key', $job->payload['debounce_session_key']);
        }

        \App\Jobs\ProcessAgentWorkflowJob::dispatch($job->tenant_id, $payload)->onQueue('ai-heavy');

        return response()->json([
            'message' => 'Job retried successfully.',
        ]);
    }
}
