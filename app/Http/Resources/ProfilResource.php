<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Profil akun sendiri. Tanpa kata sandi, tanpa no_identitas (data sensitif yang diisi admin), tanpa token. */
class ProfilResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->pelanggan;

        return [
            'id' => $this->id_akun,
            'nama' => $this->nama_tampil,
            'email' => $this->email,
            'role' => $this->role,
            'no_hp' => $p?->no_hp,
            'provinsi' => $p?->provinsi,
            'kota' => $p?->kota,
            'foto' => $this->foto_url,
            'email_terverifikasi' => $this->email_terverifikasi_at !== null,
        ];
    }
}
