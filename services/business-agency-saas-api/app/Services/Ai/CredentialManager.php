<?php

// app/Services/Ai/CredentialManager.php

namespace App\Services\Ai;

use App\Models\Integration;
use App\Models\Tenant;
use Exception;
use Illuminate\Support\Facades\Log;

class CredentialManager
{
    // public function getProviderConfig(Tenant $tenant): array
    // {
    //     $settings = $tenant->settings; // Assuming hasOne relationship

    //     if (!$settings) {
    //         throw new \RuntimeException("Tenant settings not configured.");
    //     }

    //     // Debug: Check if the model attribute is actually being cast to an array
    //     $creds = $settings->api_creds;

    //     if (!is_array($creds)) {
    //         throw new \Exception("api_creds is not being cast to an array. Value: " . json_encode($creds));
    //     }

    //     $selected = $settings->ai_provider_default ?? 'openai';

    //     // $settings->api_creds is already a decrypted array thanks to casting
    //     $creds = $settings->api_creds ?? [];

    //     // Dynamic key lookup: 'openai_key' or 'gemini_key'
    //     $keyName = "{$selected}_key";

    //     if (!isset($creds[$keyName])) {
    //         // This will show you exactly what keys ARE present
    //         throw new \Exception("Missing Key for {$selected}. Available keys: " . implode(', ', array_keys($creds)));
    //     }

    //     if (!isset($creds[$keyName]) || empty($creds[$keyName])) {
    //         // Debugging: Log what keys actually exist to help you fix the DB entry
    //         Log::error("AI Credential Error", [
    //             'tenant_id' => $tenant->id,
    //             'requested_key' => $keyName,
    //             'available_keys' => array_keys($creds)
    //         ]);

    //         throw new \Exception("Missing Key for {$selected}. Ensure 'api_creds' contains '{$keyName}'");
    //     }

    //     // gemini-3-flash-preview, gemini-2.5-flash, gemini-2.0-flash
    //     return [
    //         'provider' => $selected,
    //         'apiKey'   => $creds[$keyName],
    //         'model'    => $creds['model_preference'] ?? ($selected === 'gemini' ? 'gemini-2.0-flash' : 'gpt-4-turbo')
    //     ];
    // }

    public function getProviderConfig(Tenant $tenant, string $service = 'openai'): array
    {

        // 1. Check if AI is allowed via Plan Limit (Your optimized check)
        if ($tenant->ai_limit === 0) {
            throw new Exception('AI features are disabled for this plan.');
        }

        // 2. Fetch Active Integration
        $integration = Integration::where('tenant_id', $tenant->id)
            ->where('service', $service)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            throw new Exception("No API Key found for {$service}. Please configure it in Settings > Integrations.");
        }

        // 3. Decrypt and Map
        $creds = $integration->value; // Decrypted automatically
        $config = config("ai_providers.services.{$service}");

        // 4. Return Standardized Payload for Sidecar
        return [
            'provider' => $service,
            'apiKey' => $creds['api_key'] ?? $creds['token'] ?? null,
            'model' => $creds['model_preference'] ?? $config['default_model'],
            'meta' => $creds, // Pass full array for extra fields like org_id or app_id
        ];
    }
}
