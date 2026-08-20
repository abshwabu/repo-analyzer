<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateAiSummaryRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:openai,anthropic,claude'],
            'api_key' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $apiKey = request()->header('X-AI-API-Key') ?? request()->input('api_key');
            if (empty($apiKey)) {
                $validator->errors()->add('api_key', 'An API key must be provided in the request body (api_key) or via the X-AI-API-Key header.');
            }
        });
    }
}
