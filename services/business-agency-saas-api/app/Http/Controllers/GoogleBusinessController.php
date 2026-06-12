<?php

namespace App\Http\Controllers;

use App\Models\GoogleAccount;
use App\Models\Lead;
use App\Services\GoogleBusinessProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleBusinessController extends Controller
{
    protected $googleService;

    public function __construct(GoogleBusinessProfileService $googleService)
    {
        $this->googleService = $googleService;
    }

    public function connect(Request $request)
    {
        $tenantId = $request->user()->current_tenant_id;
        $state = encrypt(['tenant_id' => $tenantId]);

        return response()->json([
            'url' => $this->googleService->getAuthUrl($state),
        ]);
    }

    public function callback(Request $request)
    {
        $code = $request->get('code');
        $state = $request->get('state');

        if (! $code || ! $state) {
            return redirect(config('app.frontend_url').'/admin/integrations?error=missing_params');
        }

        try {
            $decrypted = decrypt($state);
            $tenantId = $decrypted['tenant_id'];

            $tokens = $this->googleService->exchangeCodeForTokens($code);
            $locationName = $this->googleService->discoverLocation($tokens['access_token']);

            GoogleAccount::updateOrCreate(
                ['tenant_id' => $tenantId],
                [
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? null,
                    'expires_at' => now()->addSeconds($tokens['expires_in']),
                    'location_name' => $locationName,
                    'is_active' => true,
                ]
            );

            return redirect(config('app.frontend_url').'/admin/integrations?success=google_connected');
        } catch (\Exception $e) {
            Log::error('[GoogleBusinessController] Callback failed: '.$e->getMessage());

            return redirect(config('app.frontend_url').'/admin/integrations?error=callback_failed');
        }
    }

    public function postReviewReply(Lead $lead)
    {
        if ($lead->source !== 'google_review') {
            return response()->json(['error' => 'Not a google review lead'], 400);
        }

        $payload = $lead->payload;
        $reviewId = $payload['google_review_id'] ?? null;
        $reply = $payload['reply_draft'] ?? null;

        if (! $reviewId || ! $reply) {
            return response()->json(['error' => 'Missing review ID or reply draft'], 400);
        }

        // Resolve the GoogleAccount for this tenant
        // For now we assume one account per tenant
        $account = GoogleAccount::where('tenant_id', $lead->tenant_id)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Google Business account not connected'], 400);
        }

        $success = $this->googleService->postReply($account, $reviewId, $reply);

        if ($success) {
            $lead->update(['status' => 'posted']);

            // Log activity
            $lead->activities()->create([
                'tenant_id' => $lead->tenant_id,
                'type' => 'review_replied',
                'description' => 'AI-drafted reply posted to Google Business Profile.',
                'properties' => ['reply' => $reply],
            ]);

            return response()->json(['message' => 'Reply posted successfully']);
        }

        return response()->json(['error' => 'Failed to post reply to Google'], 500);
    }
}
