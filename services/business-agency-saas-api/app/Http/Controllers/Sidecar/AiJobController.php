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

        if (! $job) {
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
}
