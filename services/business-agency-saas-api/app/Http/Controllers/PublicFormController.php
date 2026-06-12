<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PublicFormController extends Controller
{
    protected $leadService;

    public function __construct(\App\Services\LeadService $leadService)
    {
        $this->leadService = $leadService;
    }

    public function show(string $uuid)
    {
        $form = Form::where('id', $uuid)
            ->where('form_source', 'system')
            ->where('is_active', true)
            ->first();

        // Manual check prevents default Laravel 404 page, returns JSON instead
        if (! $form) {
            return response()->json(['message' => 'This form is not found or temporarily closed.'], 404);
        }

        return response()->json([
            'id' => $form->id,
            'name' => $form->name,
            'schema' => $form->schema,
        ]);
    }

    public function submit(Request $request, string $uuid)
    {

        // 1. Fast lookup (Read operation - safe outside transaction)
        $cacheKey = "form_submit_{$uuid}";
        $form = Cache::remember($cacheKey, 120, function () use ($uuid) {
            return Form::select(['id', 'tenant_id', 'is_active', 'name', 'form_source'])
                ->where('id', $uuid)
                ->where('form_source', 'system')
                ->where('is_active', true)
                ->first();
        });

        if (! $form) {
            return response()->json(['message' => 'Form not found or closed.'], 404);
        }

        $lead = $this->leadService->processSubmission($form, $request->all());

        return response()->json([
            'message' => 'Submitted successfully.',
            'lead_id' => $lead->id,
        ], 201);
    }

    public function tallyFormSubmit(Request $request)
    {
        // 1. Fast lookup (Read operation - safe outside transaction)
        $tallyFormId = $request->input('data.formId') ?? str()->random(6);

        $form = Form::select(['id', 'tenant_id', 'is_active', 'name', 'form_source'])
            ->where('form_source', 'tally')
            ->where('ref_form_id', $tallyFormId)
            ->where('is_active', true)
            ->first();

        if (! $form) {
            Log::warning('Tally Form Submission Failed: Form not found for formId '.$tallyFormId);

            return response()->json(['message' => 'Form not found or closed.'], 404);
        }

        $tallyFormName = $request->input('data.formName') ?? $form->name;

        // Update Form Name only once if different
        if (strtolower($form->name) !== strtolower($tallyFormName)) {
            $form->updateQuietly(['name' => $tallyFormName]);
        }

        $trimmedData = $this->flattenTallyFields($request->input('data.fields', []));

        Log::info('Tally Form Submission Raw '.json_encode($request->all()));

        $this->leadService->processSubmission($form, $trimmedData);

        return response()->json([
            'message' => 'Tally form submission received.',
        ], 201);
    }

    private function flattenTallyFields(array $fields): array
    {
        $flat = [];

        foreach ($fields as $field) {
            // 1. FIX KEYS: Use 'label' (e.g., "full_name") because 'key' is random ("question_8K...")
            $key = $field['label'] ?? $field['key'];

            // 2. EXTRACT VALUE
            $type = $field['type'] ?? 'INPUT_TEXT';
            $value = $field['value'] ?? null;

            if ($value === null) {
                $flat[$key] = null;

                continue;
            }

            // 3. HANDLE TYPES
            if ($type === 'DROPDOWN' || $type === 'MULTIPLE_CHOICE') {
                // Dropdown value comes as an array of IDs: ["uuid-string"]
                // We need to find the matching text in 'options'
                $selectedId = is_array($value) ? ($value[0] ?? null) : $value;
                $resolvedText = $selectedId; // Default to ID if not found

                if (! empty($field['options']) && $selectedId) {
                    foreach ($field['options'] as $option) {
                        if ($option['id'] === $selectedId) {
                            $resolvedText = $option['text'];
                            break;
                        }
                    }
                }
                $flat[$key] = $resolvedText;
            } elseif ($type === 'MULTI_SELECT') {
                // Multi-select returns an array of IDs. We need to map ALL of them.
                // Example: ["uuid1", "uuid2"] -> ["SOP Automation", "AI Chatbots"]

                $selectedIds = is_array($value) ? $value : [$value];
                $resolvedValues = [];

                if (! empty($field['options'])) {
                    // Create a quick lookup map: [uuid => text]
                    $optionsMap = array_column($field['options'], 'text', 'id');

                    foreach ($selectedIds as $id) {
                        if (isset($optionsMap[$id])) {
                            $resolvedValues[] = $optionsMap[$id];
                        } else {
                            // Keep ID if text not found (safety)
                            $resolvedValues[] = $id;
                        }
                    }
                } else {
                    // No options provided? Just return raw IDs
                    $resolvedValues = $selectedIds;
                }

                $flat[$key] = $resolvedValues;
            } else {
                // Handle INPUT_TEXT, INPUT_EMAIL, TEXTAREA directly
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    public function thirdPartyFormSubmit(Request $request)
    {
        // 1. Form Lookup
        $formId = $request->input('data.formId') ?? null;
        $formSource = $request->input('data.formSource') ?? 'third_party';

        $cacheKey = "form_submit_{$formId}";
        $form = Cache::remember($cacheKey, 120, function () use ($formId, $formSource) {
            return Form::select(['id', 'tenant_id', 'is_active', 'name', 'form_source', 'fields_needed'])
                ->where('form_source', $formSource)
                ->where('ref_form_id', $formId ?? str()->random(6))
                ->where('is_active', true)
                ->first();
        });

        if (! $form) {
            Log::warning("Third Party Form Submission Failed: Form not found (ID: {$formId}, Source: {$formSource})");

            return response()->json(['message' => 'Form not found or closed.'], 404);
        }

        // 2. Data Processing
        $rawData = $request->input('data.fields', []);

        if (empty($rawData)) {
            Log::error('No data received', [
                'form_id' => $formId,
                'form' => $form->getAttributes(),
                'request' => $request->all(),
            ]);

            return response()->json(['message' => 'No data received.'], 400);
        }

        $trimmedData = $this->filterPayload($rawData, $form);

        // 3. Process Lead
        $metadata = [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'origin' => $request->header('origin'),
            'referrer' => $request->header('referer'),
            'bot_ms_since_load' => $request->input('data.ms_since_load'),
            'bot_honeypot_filled' => $request->input('data.fields.company_name_verification'),
        ];

        $this->leadService->processSubmission($form, $trimmedData, $metadata);

        return response()->json([
            'message' => "{$formSource} form submission received.",
        ], 201);
    }

    /**
     * Helper: Payload Filtering based on mapping or cleaning
     */
    private function filterPayload(array $data, Form $form): array
    {
        if (! empty($form->fields_needed) && is_array($form->fields_needed)) {
            $filtered = keys_only($data, array_keys($form->fields_needed));
            Log::info('Payload filtered using field mapping: '.json_encode($filtered));

            return $filtered;
        }

        // Clean keys (e.g., remove '[]' from multi-select keys)
        $cleanData = [];
        foreach ($data as $key => $value) {
            $cleanKey = str_replace('[]', '', $key);
            $cleanData[$cleanKey] = $value;
        }

        return $cleanData;
    }
}
