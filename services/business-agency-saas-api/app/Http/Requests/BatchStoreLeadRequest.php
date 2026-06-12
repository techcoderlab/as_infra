<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchStoreLeadRequest extends FormRequest
{
    /**
     * @bodyParam leads object[] required Array of lead data objects. Example: [{"name": "Lead 1"}, {"name": "Lead 2"}]
     * @bodyParam form_id integer nullable The form ID for all leads. Example: 5
     * @bodyParam source string nullable Global source for these leads. Example: n8n
     * @bodyParam status string nullable Default status for these leads. Example: new
     * @bodyParam temperature string nullable Default temperature for these leads. Example: cold
     * @bodyParam from string nullable system_inserted or external_system_inserted. Example: external_system_inserted
     */
    public function rules(): array
    {
        return [
            'leads' => 'required|array|min:1',
            'leads.*' => 'required|array',
            'form_id' => 'nullable|exists:forms,id',
            'from' => [
                'nullable',
                'string',
                \Illuminate\Validation\Rule::in(['system_inserted', 'external_system_inserted']),
            ],
            'status' => 'nullable|string|max:50',
            'temperature' => 'nullable|string|max:50',
            'source' => 'nullable|string|max:50',
        ];
    }
}
