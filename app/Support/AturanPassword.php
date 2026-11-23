<?php

namespace App\Support;

/** Aturan kata sandi satu-satunya: register, ganti kata sandi, dan reset kata sandi memakai ini. */
final class AturanPassword
{
    /** @param  string  $konfirmasi  nama field konfirmasi (register: password_confirmation, lainnya: konfirmasi) */
    public static function rules(string $konfirmasi): array
    {
        return ['required', 'string', 'min:8', 'regex:/\d/', "confirmed:{$konfirmasi}"];
    }

    /** @return array<string,string> pesan Bahasa Indonesia untuk field kata sandi baru */
    public static function pesan(string $field): array
    {
        return [
            "{$field}.required" => 'Kata sandi baru wajib diisi',
            "{$field}.min" => 'Kata sandi minimal 8 karakter',
            "{$field}.regex" => 'Kata sandi harus mengandung angka',
            "{$field}.confirmed" => 'Konfirmasi kata sandi tidak cocok',
        ];
    }
}
