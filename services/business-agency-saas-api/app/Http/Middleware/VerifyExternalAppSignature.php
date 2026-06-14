<?php

// ─────────────────────────────────────────────────────
// Module   : VerifyExternalAppSignature
// ─────────────────────────────────────────────────────
// Pillar Compliance:
// [x] P0 Stack Bootstrap (PHP 8.2+, Laravel)
// [x] P2 Security (HMAC Validation, Replay Prevention, DB fallback decryption)
// [x] P4 Performance (Redis first, DB fallback)
// [x] P6 Resilience (Graceful degradation on Redis failure)
// [x] P7 Observability (Logs unauthorized attempts)

namespace App\Http\Middleware;

use App\Models\ExternalApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class VerifyExternalAppSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $appId = $request->header('X-App-Id');
        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        // 1. Missing Headers Check
        if (! $appId || ! $signature || ! $timestamp) {
            Log::warning('[VerifyExternalAppSignature] Missing headers', [
                'ip' => $request->ip(),
                'url' => $request->url(),
            ]);

            return response()->json(['error' => 'Unauthorized: Missing security headers'], 401);
        }

        // 2. Replay Attack Prevention (60-second window)
        if (abs(time() - (int) $timestamp) > 60) {
            Log::warning('[VerifyExternalAppSignature] Request expired', [
                'appId' => $appId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized: Request expired'], 401);
        }

        // 3. Fetch Secret (Redis Primary, DB Fallback)
        $secret = null;
        try {
            $secret = Redis::get("apikey:{$appId}");
        } catch (\Exception $e) {
            Log::error('[VerifyExternalAppSignature] Redis fetch failed: '.$e->getMessage());
            // Proceed to DB fallback
        }

        if (! $secret) {
            try {
                $keyRecord = ExternalApiKey::where('app_id', $appId)
                    ->where('is_active', true)
                    ->first();

                if ($keyRecord) {
                    $secret = decrypt($keyRecord->secret);

                    // Repopulate Redis asynchronously or silently fail if Redis is down
                    try {
                        Redis::set("apikey:{$appId}", $secret);
                    } catch (\Exception $e) {
                        // Ignore Redis write failure
                    }
                }
            } catch (\Exception $e) {
                Log::error('[VerifyExternalAppSignature] DB fetch/decrypt failed: '.$e->getMessage());
            }
        }

        if (! $secret) {
            Log::warning('[VerifyExternalAppSignature] Invalid App ID or revoked', ['appId' => $appId]);

            return response()->json(['error' => 'Unauthorized: Invalid App ID'], 401);
        }

        // 4. Verify Signature
        $expectedSignature = create_valid_signature($secret, $timestamp, $request->getContent());

        if (! hash_equals($expectedSignature, (string) $signature)) {
            Log::warning('[VerifyExternalAppSignature] Signature mismatch', [
                'appId' => $appId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized: Invalid signature'], 401);
        }

        return $next($request);
    }
}
