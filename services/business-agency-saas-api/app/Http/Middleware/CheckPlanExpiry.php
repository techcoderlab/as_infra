<?php

namespace App\Http\Middleware;

use App\Services\TenantManager;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;

class CheckPlanExpiry
{
    public function handle(Request $request, Closure $next)
    {

        $tenant = app(TenantManager::class)->getActiveTenant();

        if ($request->user()->isSuperAdmin()) {
            return $next($request);
        }

        if (! $tenant) {
            return response()->json(['message' => 'Tenant context missing.'], 403);
        }

        // Get the current active plan from your pivot table
        $activePlan = $tenant->plans
            ->first();

        if (! $activePlan) {
            return response()->json(['message' => 'No active plan found for this tenant.'], 403);
        }

        $expiryDate = $activePlan->pivot->expires_at;

        if ($expiryDate) {
            $expiresAt = Carbon::parse($expiryDate);
            // Get grace period from plan, default to 3 if not set
            $graceDays = $activePlan->pivot->grace_period_days ?? 3;
            $hardCutoff = $expiresAt->copy()->addDays($graceDays);

            if (now()->greaterThan($hardCutoff)) {
                return response()->json([
                    'error' => 'subscription_expired',
                    'message' => "Your access has been suspended on {$expiresAt->format('d M, Y')}. Please renew your subscription to regain access.",
                    // 'details' => [
                    // 'expired_at' => $expiresAt->toDateTimeString(),
                    // 'grace_period_ended' => $hardCutoff->toDateTimeString(),
                    // ]
                ], 402); // 402 Payment Required
            }
        }

        return $next($request);
    }
}
