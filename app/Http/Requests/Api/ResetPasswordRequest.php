<?php

namespace App\Http\Requests\Api;

use App\Support\AturanPassword;

/** POST /auth/reset-password (password_confirmation tetap diterima sebagai ganti konfirmasi). */
class ResetPasswordRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->aliasField(['password_confirmation' => 'konfirmasi']);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'token' => ['required', 'string'],
            'password' => AturanPassword::rules('konfirmasi'),
        ];
    }

    public function messages(): array
    {
        return ['email.required' => 'Email wajib diisi', 'email.email' => 'Format email tidak valid', 'token.required' => 'Token reset wajib diisi']
            + AturanPassword::pesan('password');
    }
}
