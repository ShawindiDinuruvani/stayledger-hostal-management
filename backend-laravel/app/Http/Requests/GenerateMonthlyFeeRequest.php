<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateMonthlyFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'billing_period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'due_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'billing_period.required' => 'The billing period is required.',
            'billing_period.regex' => 'The billing period must be in YYYY-MM format (e.g. 2026-11).',
            'amount.required' => 'The fee amount is required.',
            'amount.gt' => 'The fee amount must be a positive number greater than zero.',
            'due_date.required' => 'The payment due date is required.',
            'due_date.date' => 'The payment due date must be a valid date.',
        ];
    }
}
