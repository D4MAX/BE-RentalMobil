<?php

namespace App\Http\Requests\Api;

use App\Support\AturanPassword;

/** PUT /profil/password. */
class GantiPasswordRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'password_lama' => ['required', 'string'],
            'password_baru' => AturanPassword::rules('konfirmasi'),
        ];
    }

    public function messages(): array
    {
        return ['password_lama.required' => 'Kata sandi lama wajib diisi'] + AturanPassword::pesan('password_baru');
    }
}
