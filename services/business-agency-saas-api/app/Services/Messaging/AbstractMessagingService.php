<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessagingProviderInterface;
use App\Models\Integration;

abstract class AbstractMessagingService implements MessagingProviderInterface
{
    protected $tenantId;

    protected $integration;

    public function __construct(int $tenantId, ?Integration $integration = null)
    {
        $this->tenantId = $tenantId;

        $this->integration = $integration ?? Integration::where('tenant_id', $tenantId)
            ->where('service', $this->getServiceKey())
            ->where('is_active', true)
            ->first();
    }

    /**
     * Get the integration service key (e.g., 'whatsapp', 'telegram')
     */
    abstract protected function getServiceKey(): string;

    /**
     * Internal helper to handle provider-specific request preparation and execution.
     */
    abstract protected function _sendRequest(array $payload);
}
