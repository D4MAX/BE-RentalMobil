<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AkunResource extends JsonResource
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
        ];
    }
}
