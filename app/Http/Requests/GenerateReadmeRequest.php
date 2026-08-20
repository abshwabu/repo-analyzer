<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateReadmeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'in:openai,anthropic,claude'],
            'api_key' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:100'],
        ];
    }
}
