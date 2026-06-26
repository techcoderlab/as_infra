<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    /**
     * GET /api/integrations/available
     * Returns the form schemas for the UI to render.
     */
    public function availableServices()
    {
        // FIX: Get the raw array, do NOT wrap in response()->json() yet
        $integrations = config('ai_providers.services');

        if (! $integrations || ! is_array($integrations)) {
            return response()->json([]);
        }

        $available = collect($integrations)
            ->filter(fn ($s) => $s['enabled'] ?? false) // Safety check using ??
            ->map(function ($details, $key) {
                return [
                    'id' => $key,
                    'name' => $details['name'],
                    'logo' => $details['logo'] ?? '',
                    'fields' => $details['fields'] ?? [],
                    'default_model' => $details['default_model'] ?? null,
                ];
            })
            ->values();

        // FIX: Return the JSON response only after processing is done
        return response()->json($available);
    }

    /**
     * GET /api/integrations
     * List user's connected services (Masked).
     */
    public function index(Request $request)
    {
        $tenantId = $request->user()->current_tenant_id; // Assuming auth structure

        return Integration::where('tenant_id', $tenantId)
            ->get()
            ->map(function ($integration) {
                // Return metadata but NEVER return the full API key
                $creds = $integration->value;
                $maskedCreds = [];

                foreach ($creds as $k => $v) {
                    // Show only first 4 chars for verification
                    $maskedCreds[$k] = substr($v, 0, 4).'...';
                }

                return [
                    'id' => $integration->id,
                    'service' => $integration->service,
                    'name' => config("ai_providers.services.{$integration->service}.name") ?? $integration->service, // Fallback to raw ID if config is missing
                    'masked_value' => $maskedCreds,
                    'is_active' => $integration->is_active,
                    'is_brain' => $integration->is_brain,
                    'created_at' => $integration->created_at,
                ];
            });
    }

    /**
     * POST /api/integrations
     * Securely store the keys.
     */
    public function store(Request $request)
    {
        // Get list of valid service keys
        $services = array_keys(config('ai_providers.services') ?? []);

        $validator = Validator::make($request->all(), [
            'service' => ['required', Rule::in($services)],
            'value' => 'required|array', // Ensure 'value' is an array from frontend
            'key' => 'nullable|string',
            'is_active' => 'required|boolean',
            'is_brain' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // if (is_array($request->value)) {
        //     $values = implode(',', $request->value);
        //     return response()->json([
        //         'message' => "{$values}"
        //     ], 422);
        // }

        // Dynamic Validation based on config
        $requiredFields = config("ai_providers.services.{$request->service}.fields") ?? [];

        foreach ($requiredFields as $field) {
            $fieldName = $field['name'];

            // Check if required AND if missing from the 'value' array
            if (($field['required'] ?? false) && empty($request->input("value.$fieldName"))) {
                return response()->json([
                    'message' => "The {$field['label']} field is required.",
                ], 422);
            }
        }

        // Save to DB
        $integration = Integration::updateOrCreate(
            [
                'tenant_id' => $request->user()->current_tenant_id,
                'service' => $request->service,
                // If you support multiple keys per service, use $request->key.
                // If one key per service, hardcode 'default'.
                'key' => strtolower($request->service).'_key',
            ],
            [
                'value' => $request->value, // Casts to JSON automatically if model is set up
                'is_active' => $request->is_active,
                'is_brain' => $request->is_brain,
            ]
        );

        $creds = $integration->value ?? [];
        $maskedCreds = [];
        foreach ($creds as $k => $v) {
            $maskedCreds[$k] = substr($v, 0, 4).'...';
        }
        
        $entry = [
            'id' => $integration->id,
            'service' => $integration->service,
            'name' => config("ai_providers.services.{$integration->service}.name") ?? $integration->service,
            'masked_value' => $maskedCreds,
            'is_active' => $integration->is_active,
            'is_brain' => $integration->is_brain,
            'created_at' => $integration->created_at,
        ];

        return response()->json([
            'success' => true,
            'entry' => $entry, // Return entry so Vue can update list immediately
            'message' => 'Connected successfully.',
        ]);
    }

    public function update(Request $request, $id)
    {
        $integration = Integration::where('tenant_id', $request->user()->current_tenant_id)
            ->where('id', $id)
            ->firstOrFail();

        // Get list of valid service keys
        $services = array_keys(config('ai_providers.services') ?? []);

        $validator = Validator::make($request->all(), [
            'service' => ['sometimes', 'required', Rule::in($services)],
            'value' => 'sometimes|array', // Ensure 'value' is an array from frontend
            'is_active' => 'sometimes|boolean',
            'is_brain' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Dynamic Validation based on config - only for fields present in the request
        if ($request->has('value')) {
            $serviceName = $request->input('service', $integration->service);
            $requiredFields = config("ai_providers.services.{$serviceName}.fields") ?? [];

            foreach ($requiredFields as $field) {
                $fieldName = $field['name'];
                // Only validate if the field is present in the partial payload
                if (array_key_exists($fieldName, $request->input('value'))) {
                    if (($field['required'] ?? false) && empty($request->input("value.$fieldName"))) {
                        return response()->json([
                            'message' => "The {$field['label']} field is required.",
                        ], 422);
                    }
                }
            }

            // Merge with existing values gracefully
            $existingValue = $integration->value ?? [];
            $integration->value = array_merge($existingValue, $request->input('value'));
        }

        if ($request->has('is_active')) {
            $integration->is_active = $request->input('is_active');
        }

        if ($request->has('is_brain')) {
            $integration->is_brain = $request->input('is_brain');
        }

        $integration->save();

        $creds = $integration->value ?? [];
        $maskedCreds = [];
        foreach ($creds as $k => $v) {
            $maskedCreds[$k] = substr($v, 0, 4).'...';
        }
        
        $entry = [
            'id' => $integration->id,
            'service' => $integration->service,
            'name' => config("ai_providers.services.{$integration->service}.name") ?? $integration->service,
            'masked_value' => $maskedCreds,
            'is_active' => $integration->is_active,
            'is_brain' => $integration->is_brain,
            'created_at' => $integration->created_at,
        ];

        return response()->json([
            'success' => true,
            'entry' => $entry, // Return entry so Vue can update list immediately
            'message' => 'Updated successfully.',
        ]);
    }

    /**
     * DELETE /api/integrations/{id}
     */
    public function destroy(Request $request, $id)
    {
        $integration = Integration::where('tenant_id', $request->user()->current_tenant_id)
            ->where('id', $id)
            ->firstOrFail();

        $integration->delete();

        return response()->json(['success' => true]);
    }
}
