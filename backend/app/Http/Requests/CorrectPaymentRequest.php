<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CorrectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_date' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:CASH,BANK,CARD'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A correction reason is required.',
            'amount.gt' => 'Replacement payment amount must be greater than zero.',
            'paid_date.before_or_equal' => 'Payment date cannot be in the future.',
        ];
    }
}
