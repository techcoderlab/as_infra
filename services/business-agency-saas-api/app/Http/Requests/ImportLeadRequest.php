<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportLeadRequest extends FormRequest
{
    /**
     * @bodyParam file file required The CSV/TXT file to import.
     * @bodyParam status string nullable Initial status for imported leads. Example: new
     * @bodyParam temperature string nullable Initial temperature. Example: cold
     * @bodyParam form_id integer nullable Associate with a form ID. Example: 10
     * @bodyParam source string nullable Source identifier. Example: CSV Import 2026
     * @bodyParam from string nullable system_inserted or external_system_inserted. Example: system_inserted
     */
    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,txt',
            'status' => 'nullable|string|max:50',
            'temperature' => 'nullable|string|max:50',
            'form_id' => 'nullable|exists:forms,id',
            'source' => 'nullable|string|max:50',
            'from' => [
                'nullable',
                'string',
                \Illuminate\Validation\Rule::in(['system_inserted', 'external_system_inserted']),
            ],
        ];
    }
}
