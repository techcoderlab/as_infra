<?php

namespace App\Services;

use App\Contracts\Messaging\MessagingProviderInterface;
use App\Models\Integration;
use App\Services\Messaging\AbstractMessagingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class WhatsAppServiceNative extends AbstractMessagingService implements MessagingProviderInterface
{
    protected $baseUrl;

    protected $apiVersion;

    protected $limit;

    public function __construct(int $tenantId, ?Integration $integration = null)
    {
        parent::__construct($tenantId, $integration);

        $this->baseUrl = config('whatsapp.base_url', 'https://graph.facebook.com');
        $this->apiVersion = config('whatsapp.graph_api_version', 'v21.0');
        $this->limit = config('whatsapp.whatsapp_per_minute_limit', 10);
    }

    protected function getServiceKey(): string
    {
        return 'whatsapp';
    }

    /**
     * Send a generic message payload.
     */
    public function sendMessage(string $to, $content, array $options = [])
    {
        $type = $options['type'] ?? 'text';

        if ($type === 'template') {
            return $this->sendTemplate($to, $content, $options['language'] ?? 'en_US');
        }

        return $this->sendText($to, (string) $content);
    }

    /**
     * Send a plain text message.
     */
    protected function sendText(string $to, string $body)
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => $body],
        ];

        return $this->_sendRequest($payload);
    }

    /**
     * Send a template message.
     */
    protected function sendTemplate(string $to, string $templateName, string $language = 'en_US')
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
            ],
        ];

        return $this->_sendRequest($payload);
    }

    /**
     * Internal helper to handle rate limiting, preparation, and execution.
     */
    protected function _sendRequest(array $payload)
    {
        if (! $this->integration) {
            Log::error("[WhatsAppService]: No active integration for tenant {$this->tenantId}");

            return false;
        }

        $config = $this->integration->value;
        $phoneNumberId = $config['phone_id'] ?? null;
        $accessToken = $config['api_key'] ?? null;

        if (! $phoneNumberId || ! $accessToken) {
            Log::error("[WhatsAppService]: Missing config for tenant {$this->tenantId}");

            return false;
        }

        // 1. Rate Limiting (Burst Smoothing)
        // We use a per-phone-id limit. Meta usually allows ~20-80 per min depending on tier.
        // We implement a conservative limit to avoid bans.
        $limitKey = "whatsapp_limit_{$phoneNumberId}";

        if (RateLimiter::tooManyAttempts($limitKey, $this->limit)) {
            $seconds = RateLimiter::availableIn($limitKey);
            Log::warning("[WhatsAppService]: Rate limit hit for {$phoneNumberId}. Smoothing burst, waiting {$seconds}s");

            // If we are in a high-volume scenario, we might want to sleep or just fail and let the queue retry.
            // For robustness, we'll wait briefly if it's small, otherwise we'll log and return false.
            if ($seconds <= 2) {
                sleep($seconds);
            } else {
                return false;
            }
        }
        RateLimiter::hit($limitKey, 60); // 20 per minute

        // 2. Execute Request
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$phoneNumberId}/messages";

        try {
            Log::info("[WhatsAppService]: Sending message to {$payload['to']} via {$phoneNumberId}");

            // Use sync call by default for better error handling in high-volume queue contexts.
            // If async is truly needed, it should be handled by Laravel Queues.
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->connectTimeout(5)
                ->post($url, $payload);

            if ($response->successful()) {
                return $response->json();
            }

            $error = $response->json()['error']['message'] ?? 'Unknown Meta API error';
            Log::error("[WhatsAppService]: Meta API Failed for {$phoneNumberId}: {$error}", [
                'status' => $response->status(),
                'response' => $response->json(),
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('[WhatsAppService]: Exception sending message: '.$e->getMessage());

            return false;
        }
    }
}
