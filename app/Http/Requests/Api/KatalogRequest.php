<?php

namespace App\Http\Requests\Api;

class KatalogRequest extends ApiRequest
{
    /** "any" (pilihan dropdown) berarti semua: sama dengan tidak mengisi kursi atau transmisi. */
    protected function prepareForValidation(): void
    {
        foreach (['kursi', 'transmisi'] as $kolom) {
            if (is_string($this->input($kolom)) && strtolower(trim($this->input($kolom))) === 'any') {
                $this->merge([$kolom => null]);
            }
        }
    }

    /** Pada daftar tanggal dan durasi wajib; pada detail tipe keduanya opsional. */
    public function rules(): array
    {
        $wajib = $this->route('id') === null ? 'required' : 'nullable';

        return [
            'tgl_mulai' => [
                $wajib, 'date_format:Y-m-d',
                'after_or_equal:'.now()->addDay()->toDateString(),
                'before_or_equal:'.now()->addDays(config('rental.maks_hari_ke_depan'))->toDateString(),
            ],
            'durasi' => [$wajib, 'integer', 'min:1', 'max:'.config('rental.maks_durasi')],
            'kursi' => ['nullable', 'integer', 'min:1'],
            'transmisi' => ['nullable', 'in:manual,matic'],
        ];
    }
}
