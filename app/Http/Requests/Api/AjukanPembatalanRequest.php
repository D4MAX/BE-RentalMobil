<?php

namespace App\Http\Requests\Api;

/**
 * Hanya alasan yang dipastikan di sini. Rekening tujuan refund wajib atau tidaknya bergantung pada channel pembayaran
 * pesanan (data di database), jadi diperiksa di PemesananService::ajukanPembatalan.
 */
class AjukanPembatalanRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'max:255'],
            'bank' => ['nullable', 'string', 'max:50'],
            'no_rekening' => ['nullable', 'string', 'max:50'],
            'nama_rekening' => ['nullable', 'string', 'max:100'],
        ];
    }
}
