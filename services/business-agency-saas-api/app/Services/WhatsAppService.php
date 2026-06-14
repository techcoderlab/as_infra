<?php

namespace App\Services;

use App\Contracts\Messaging\MessagingProviderInterface;
use App\Models\Integration;
use App\Services\Messaging\AbstractMessagingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppService extends AbstractMessagingService implements MessagingProviderInterface
{
    public function __construct(int $tenantId, ?Integration $integration = null)
    {
        parent::__construct($tenantId, $integration);
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
            'type' => 'text',
            'text_body' => $body,
        ];

        return $this->_sendRequest($this->buildPayload($to, $payload));
    }

    /**
     * Send a template message.
     */
    protected function sendTemplate(string $to, string $templateName, string $language = 'en_US')
    {
        $payload = [
            'type' => 'template',
            'template_name' => $templateName,
            'language' => $language,
        ];

        return $this->_sendRequest($this->buildPayload($to, $payload));
    }

    /**
     * Helper to build the unified payload structure for the Python sidecar.
     */
    protected function buildPayload(string $to, array $messageData): array
    {
        $config = $this->integration ? $this->integration->value : [];

        return [
            'config' => [
                'phone_number_id' => (string) ($config['phone_id'] ?? ''),
                'access_token' => $config['api_key'] ?? '',
            ],
            'message' => array_merge([
                'recipient_phone' => $to,
                'trace_id' => (string) Str::uuid(),
            ], $messageData),
        ];
    }

    /**
     * Internal helper to sign and dispatch requests to the MCP Sidecar.
     */
    protected function _sendRequest(array $data)
    {
        if (! $this->integration) {
            Log::error("[WhatsAppService]: No active integration for tenant {$this->tenantId}");

            return false;
        }

        if (empty($data['config']['phone_number_id']) || empty($data['config']['access_token'])) {
            Log::error("[WhatsAppService]: Missing config for tenant {$this->tenantId}");

            return false;
        }

        $sidecarEndpoint = '/v1/wa/send';
        $url = config('services.mcp_sidecar.url').$sidecarEndpoint;
        $secret = config('services.mcp_sidecar.token');
        $timestamp = time();

        $jsonBody = json_encode($data);

        $appId = config('services.mcp_sidecar.client_app_id');
        $secret = config('services.mcp_sidecar.client_secret');
        $signature = create_valid_signature($secret, $timestamp, $jsonBody);

        Log::info("[WhatsAppService]: Dispatching to Sidecar for {$data['message']['recipient_phone']}");

        $response = Http::withHeaders([
            'X-App-Id' => $appId,
            'X-Signature' => $signature,
            'X-Timestamp' => $timestamp,
            'X-Service-ID' => config('services.mcp_sidecar.calling_api_name'),
            'Content-Type' => 'application/json',
        ])
            ->timeout(10)
            ->connectTimeout(5)
            ->withBody($jsonBody, 'application/json')
            ->post($url);

        if ($response->failed()) {
            Log::error('[WhatsAppService]: Sidecar request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return $response->json();
    }
}
