<?php

namespace App\Http\Requests\Api;

/** POST /auth/lupa-password. */
class LupaPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email:rfc', 'max:255']];
    }

    public function messages(): array
    {
        return ['email.required' => 'Email wajib diisi', 'email.email' => 'Format email tidak valid'];
    }
}
