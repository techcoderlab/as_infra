<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\MessagingProviderInterface;
use App\Services\WhatsAppService;
use App\Services\WhatsAppServiceNative;
use Exception;

class MessagingServiceFactory
{
    /**
     * Resolve the messaging service by type.
     *
     * @param  string  $type  The service type (e.g., 'whatsapp', 'whatsapp_native').
     *
     * @throws Exception
     */
    public static function make(string $type, int $tenantId): MessagingProviderInterface
    {
        return match ($type) {
            'whatsapp_native' => new WhatsAppServiceNative($tenantId),
            'whatsapp' => new WhatsAppService($tenantId),
            // 'telegram' => new TelegramService($tenantId),
            default => throw new Exception("Unsupported messaging service type: {$type}"),
        };
    }
}
