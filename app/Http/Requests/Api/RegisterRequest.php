<?php

namespace App\Http\Requests\Api;

use App\Support\AturanPassword;

class RegisterRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'nama' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'no_hp' => ['required', 'regex:/^(\+62|62|0)8[1-9][0-9]{6,11}$/'],
            'provinsi' => ['required', 'string', 'max:100'],
            'kota' => ['required', 'string', 'max:100'],
            'password' => AturanPassword::rules('password_confirmation'),
        ];
    }

    public function messages(): array
    {
        return [
            'password.regex' => 'Kata sandi harus mengandung angka',
            'no_hp.regex' => 'Nomor telepon atau WhatsApp tidak valid',
        ];
    }
}
