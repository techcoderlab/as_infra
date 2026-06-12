<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    /**
     * @bodyParam payload object required Key-value pairs representing lead data. Example: {"name": "Jane Smith"}
     * @bodyParam form_id integer nullable The ID of the source form. Example: 5
     * @bodyParam source string nullable The source of the lead. Default: undefined. Example: Manual
     * @bodyParam temperature string nullable cold, warm, or hot. Default: cold. Example: warm
     * @bodyParam suppress_webhooks boolean Whether to suppress webhooks for this creation. Example: false
     */
    public function rules(): array
    {
        return [
            'payload' => 'required|array',
            'form_id' => 'nullable|exists:forms,id',
            'source' => 'nullable|string|max:50',
            'temperature' => ['nullable', 'string', \Illuminate\Validation\Rule::in(['cold', 'warm', 'hot'])],
        ];
    }
}
