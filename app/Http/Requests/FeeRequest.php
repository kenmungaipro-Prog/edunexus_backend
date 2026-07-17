<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

// ============================================================
// FeeRequest.php
// ============================================================


class FeeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public const PAYMENT_METHODS = [
        'cash',
        'mpesa',
        'upi',
        'bank_transfer',
        'bank_deposit',
        'card',
        'cheque',
        'online',
    ];

    public function rules(): array
    {
        return [
            'student_id'     => 'required|exists:students,id',
            'fee_type_id'    => 'required|exists:fee_types,id',
            'amount'         => 'required|numeric|min:0.01|max:9999999.99',
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'transaction_id' => [
                Rule::requiredIf(fn () => in_array($this->payment_method, [
                    'mpesa',
                    'upi',
                    'bank_transfer',
                    'bank_deposit',
                    'card',
                    'online',
                ], true)),
                'nullable',
                'string',
                'max:100',
            ],
            'remarks'        => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'transaction_id.required' => 'Transaction reference is required for M-Pesa, bank, card, and online payments.',
            'amount.min'              => 'Fee amount must be greater than zero.',
        ];
    }
}
