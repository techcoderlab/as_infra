<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AiIntegrationController extends Controller
{
    public function getSettings(Request $request)
    {
        $tenant = $request->user()->tenant;
        $settings = $tenant->settings;

        return response()->json([
            'ai_provider_default' => $settings->ai_provider_default ?? 'openai',
            // Return masked versions of keys for UI
            // 'creds' => [
            //     'openai_key' => $this->mask($settings->api_creds['openai_key'] ?? ''),
            //     'gemini_key' => $this->mask($settings->api_creds['gemini_key'] ?? ''),
            // ]
        ]);
    }

    public function updateSettings(Request $request)
    {
        $request->validate([
            'ai_provider_default' => 'required|in:openai,gemini',
            // 'api_creds' => 'array',
        ]);

        $tenant = $request->user()->tenant;
        $settings = $tenant->settings; // Get existing model

        // Merge existing creds with new ones (to avoid wiping keys not sent)
        // $currentCreds = $settings->api_creds ?? [];
        // $newCreds = $request->input('api_creds', []);

        // Only update keys that are actually provided and not empty
        // foreach ($newCreds as $key => $value) {
        //     if (!empty($value)) {
        //         $currentCreds[$key] = $value;
        //     }
        // }

        $settings->update([
            'ai_provider_default' => $request->ai_provider_default,
            // 'api_creds' => $currentCreds, // Eloquent encrypts this automatically
        ]);

        return response()->json(['message' => 'AI Settings Updated']);
    }

    private function mask($key)
    {
        if (strlen($key) < 5) {
            return '';
        }

        return substr($key, 0, 3).'********'.substr($key, -3);
    }
}
