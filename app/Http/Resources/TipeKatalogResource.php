<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/** Tipe untuk publik dan user: tanpa data internal. */
class TipeKatalogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id_tipe,
            'nama' => $this->nama,
            'merk' => $this->merk,
            'kursi' => $this->kursi,
            'bahan_bakar' => $this->bahan_bakar,
            'tarif' => [
                'dengan_sopir' => $this->harga_sopir_per_hari,
                'lepas_kunci_manual' => $this->harga_lepas_kunci_manual,
                'lepas_kunci_matic' => $this->harga_lepas_kunci_matic,
            ],
            'foto' => $this->foto->map(fn ($f) => Storage::disk('public')->url($f->path_foto))->values(),
            'rating_rata' => $this->rating_rata !== null ? round((float) $this->rating_rata, 1) : null,
            'sisa_unit' => $this->sisa_unit ?? null,
        ];
    }
}
