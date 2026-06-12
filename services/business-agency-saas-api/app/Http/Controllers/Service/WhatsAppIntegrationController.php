<?php

namespace App\Http\Controllers\Service;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppIntegrationController extends Controller
{
    /**
     * Handle the implementation of the Verification Request (GET).
     *
     * @param  int  $tenantId
     * @return \Illuminate\Http\Response
     */
    public function verify(Request $request, $tenantId)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode && $token) {
            // Retrieve the integration record for this tenant
            $integration = Integration::where('tenant_id', $tenantId)
                ->where('service', 'whatsapp')
                ->where('is_active', true)
                ->first();

            if (! $integration) {
                Log::warning("WhatsApp Webhook: Integration not found or inactive for tenant {$tenantId}");

                return response('Forbidden', 403);
            }

            // Retrieve verify_token from the integration settings
            // Assuming 'value' is cast to an array/collection and contains 'verify_token'
            $settings = $integration->value;
            $verifyToken = $settings['verify_token'] ?? null;

            if ($mode === 'subscribe' && $token === $verifyToken) {
                Log::info("WhatsApp Webhook: Verified for tenant {$tenantId}");

                return response($challenge, 200);
            } else {
                Log::warning("WhatsApp Webhook: Verification failed for tenant {$tenantId}. Token mismatch.");

                return response('Forbidden', 403);
            }
        }

        return response('BadRequest', 400);
    }

    /**
     * Handle the incoming Webhook Event (POST).
     *
     * @param  int  $tenantId
     * @return \Illuminate\Http\Response
     */
    public function receive(Request $request, $tenantId)
    {
        // 1. Retrieve the integration to get the App Secret for signature verification
        $integration = Integration::where('tenant_id', $tenantId)
            ->where('service', 'whatsapp')
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            Log::warning("WhatsApp Webhook: Integration not found or inactive for tenant {$tenantId}");

            return response('Forbidden', 403);
        }

        $settings = $integration->value;
        $appSecret = $settings['app_secret'] ?? null;

        // 2. Verify Signature
        if (! $this->verifySignature($request, $appSecret)) {
            Log::warning("WhatsApp Webhook: Signature verification failed for tenant {$tenantId}");

            return response('Forbidden', 403);
        }

        // 3. Process the payload asynchronously to prevent timeouts
        // We pass the raw payload and the tenant ID to the job
        $payload = $request->all();

        // Dispatch job
        ProcessWhatsAppWebhook::dispatch($payload, $tenantId);

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Verify the X-Hub-Signature-256 header.
     *
     * @return bool
     */
    private function verifySignature(Request $request, ?string $appSecret)
    {
        if (empty($appSecret)) {
            // If no secret is configured, we can't verify.
            // DANGER: In production, you might want to force this.
            // For now, fail safe if secret is missing.
            Log::error('WhatsApp Webhook: App Secret missing in configuration.');

            return false;
        }

        $signature = $request->header('X-Hub-Signature-256');

        if (! $signature) {
            Log::warning('WhatsApp Webhook: Missing X-Hub-Signature-256 header.');

            return false;
        }

        $body = $request->getContent();

        // Calculate expected signature
        // signature format is "sha256=HASH"
        $expected = 'sha256='.hash_hmac('sha256', $body, $appSecret);

        return hash_equals($expected, $signature);
    }
}
