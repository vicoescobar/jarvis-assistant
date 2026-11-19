<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CycleTrackerRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(['advance', 'skip', 'set'])],
            'target_index' => ['required_if:action,set', 'nullable', 'integer', 'min:0'],
        ];
    }
}
