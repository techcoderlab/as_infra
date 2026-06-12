<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLeadRequest extends FormRequest
{
    /**
     * @bodyParam status string nullable The new status slug. Example: contacted
     * @bodyParam temperature string nullable Must be cold, warm, or hot. Example: warm
     * @bodyParam notes string nullable Additional system notes. Example: system_added_note
     * @bodyParam suppress_webhooks boolean Whether to stop webhooks from firing for this update. Example: true
     */
    public function rules(): array
    {
        return [
            'status' => 'sometimes|nullable|string|max:50',
            'temperature' => ['sometimes', 'nullable', 'string', \Illuminate\Validation\Rule::in(['cold', 'warm', 'hot'])],
            'notes' => ['sometimes', 'nullable', 'string', \Illuminate\Validation\Rule::in(['system_added_note', 'external_system_added_note'])],
        ];
    }
}
