<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExportLeadRequest extends FormRequest
{
    /**
     * @bodyParam ids integer[] Array of lead IDs to export. If empty, all leads for the tenant are exported. Example: [1, 2, 3]
     */
    public function rules(): array
    {
        return [
            'ids' => 'sometimes|nullable|array|min:1',
            'ids.*' => 'required|integer',
        ];
    }
}
