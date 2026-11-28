<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A reversal reason is required.',
            'reason.min' => 'The reversal reason must contain at least 3 characters.',
        ];
    }
}
