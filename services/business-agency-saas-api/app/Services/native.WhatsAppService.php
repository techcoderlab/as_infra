<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected $tenantId;

    protected $integration;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->integration = Integration::where('tenant_id', $tenantId)
            ->where('service', 'whatsapp')
            ->where('is_active', true)
            ->first();
    }

    public function sendText(string $to, string $body)
    {
        if (! $this->integration) {
            Log::error("WhatsAppService: No active integration for tenant {$this->tenantId}");

            return false;
        }

        $config = $this->integration->value;
        $phoneNumberId = $config['phone_id'] ?? null;
        $accessToken = $config['api_key'] ?? null;

        if (! $phoneNumberId || ! $accessToken) {
            Log::error("WhatsAppService: Missing config for tenant {$this->tenantId}");

            return false;
        }

        $url = "https://graph.facebook.com/v21.0/{$phoneNumberId}/messages";

        $response = Http::withToken($accessToken)->post($url, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);

        if ($response->failed()) {
            Log::error('WhatsAppService: Failed to send message. '.$response->body());

            return false;
        }

        return true;
    }
}
