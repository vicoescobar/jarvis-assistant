<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemoryRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
            'instruction' => ['required', 'string', 'max:2000'],
            'volatile' => ['sometimes', 'boolean'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
