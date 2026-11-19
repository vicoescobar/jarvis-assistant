<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JarvisLiveToolRequest extends FormRequest
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
            'tool_name' => ['required', 'string', 'max:120'],
            'arguments' => ['sometimes', 'array'],
        ];
    }
}
