<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PreviewMonthlyFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'period.required' => 'The billing period is required for generating a preview.',
            'period.regex' => 'The billing period must be in YYYY-MM format (e.g. 2026-11).',
            'amount.gt' => 'The fee amount must be greater than zero.',
        ];
    }
}
