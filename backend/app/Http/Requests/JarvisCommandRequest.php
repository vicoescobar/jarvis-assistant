<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JarvisCommandRequest extends FormRequest
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
            'command' => ['required', 'string', 'max:4000'],
            'notify_discord' => ['sometimes', 'boolean'],
        ];
    }
}
