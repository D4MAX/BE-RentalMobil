<?php

namespace App\Http\Requests\Api;

/**
 * POST /profil (multipart/form-data): hanya field yang dikirim yang diubah. User: nama, no_hp, provinsi, kota
 * (tabel pelanggan). Admin: nama (tabel akun). Email tidak bisa diubah: field email diabaikan.
 * Foto (opsional, satu per akun): `foto` mengganti foto lama, `hapus_foto=true` mengosongkan, tanpa keduanya foto lama tetap.
 */
class ProfilRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->booleanDariTeks(['hapus_foto']); // multipart mengirim boolean sebagai teks "true"/"false"
    }

    public function rules(): array
    {
        $foto = [
            // nullable: kolom foto kosong dari form dianggap tidak dikirim
            'foto' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'hapus_foto' => ['sometimes', 'nullable', 'boolean'],
        ];

        if ($this->user()?->isAdmin()) {
            return ['nama' => ['sometimes', 'required', 'string', 'max:100']] + $foto;
        }

        return [
            'nama' => ['sometimes', 'required', 'string', 'max:100'],
            'no_hp' => ['sometimes', 'required', 'regex:/^(\+62|62|0)8[1-9][0-9]{6,11}$/'],
            'provinsi' => ['sometimes', 'required', 'string', 'max:100'],
            'kota' => ['sometimes', 'required', 'string', 'max:100'],
        ] + $foto;
    }

    public function messages(): array
    {
        return [
            'nama.required' => 'Nama wajib diisi',
            'nama.max' => 'Nama maksimal 100 karakter',
            'no_hp.required' => 'Nomor telepon atau WhatsApp wajib diisi',
            'no_hp.regex' => 'Nomor telepon atau WhatsApp tidak valid',
            'provinsi.required' => 'Provinsi wajib diisi',
            'kota.required' => 'Kota wajib diisi',
            'foto.file' => 'Foto harus berupa berkas',
            'foto.mimes' => 'Foto harus berformat jpg, jpeg, png, atau webp',
            'foto.max' => 'Foto maksimal 2 MB',
            'foto.uploaded' => 'Foto gagal diunggah (maksimal 2 MB)',
            'hapus_foto.boolean' => 'hapus_foto harus true atau false',
        ];
    }
}
