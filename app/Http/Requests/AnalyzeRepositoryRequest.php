<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnalyzeRepositoryRequest extends FormRequest
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
            'github_url' => [
                'required',
                'string',
                'regex:/^(?:https?:\/\/)?(?:www\.)?github\.com\/[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+(?:\.git|\/.*)?$|^git@github\.com:[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+(?:\.git)?$/i',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'github_url.required' => 'The GitHub repository URL is required.',
            'github_url.regex' => 'The provided URL must be a valid GitHub repository URL (HTTPS or SSH format).',
        ];
    }
}
