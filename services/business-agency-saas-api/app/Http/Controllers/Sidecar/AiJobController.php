<?php

namespace App\Http\Controllers\Sidecar;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;

class AiJobController extends Controller
{
    public function monitor(Request $request, $target_id)
    {
        $lead = Lead::find($target_id);
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
