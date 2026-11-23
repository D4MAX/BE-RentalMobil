<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\TipeKendaraan;
use App\Services\KendaraanAdminService;
use App\Support\TeksNama;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * /admin/tipe (Spek API bagian 8). Ubah tipe adalah POST /admin/tipe/{id} (multipart/form-data, foto opsional): PHP tidak
 * mengurai multipart pada PUT, jadi tidak ada PUT dan tidak perlu field `_method`. Foto yang dikirim ditambahkan
 * (total maks 3); satu foto dihapus lewat DELETE /admin/tipe/{id}/foto/{id_foto}.
 */
class TipeAdminController extends Controller
{
    public function __construct(private KendaraanAdminService $kendaraan) {}

    public function index(): JsonResponse
    {
        $tipe = TipeKendaraan::query()->withCount('kendaraan')->with('foto')->orderBy('nama')->get();

        return response()->json(['data' => $tipe->map(fn ($t) => $this->kendaraan->bentukTipe($t))->values()->all()]);
    }

    public function show(int $id): JsonResponse
    {
        $t = TipeKendaraan::query()->withCount('kendaraan')->with('foto')->find($id)
            ?? throw new ApiException('Tipe tidak ditemukan', 'TIDAK_DITEMUKAN', 404);

        return response()->json(['data' => $this->kendaraan->bentukTipe($t)]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $this->validasi($request);
        $tipe = $this->kendaraan->buatTipe($request->user(), $d, $request->file('foto', []));

        return response()->json(['data' => $this->kendaraan->bentukTipe($tipe)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $d = $this->validasi($request);
        $tipe = $this->kendaraan->ubahTipe($request->user(), $id, $d, $request->file('foto', []));

        return response()->json(['data' => $this->kendaraan->bentukTipe($tipe)]);
    }

    public function hapusFoto(Request $request, int $id, int $idFoto): JsonResponse
    {
        $tipe = $this->kendaraan->hapusFoto($request->user(), $id, $idFoto);

        return response()->json(['data' => $this->kendaraan->bentukTipe($tipe)]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->kendaraan->hapusTipe($request->user(), $id);

        return response()->noContent();
    }

    private function validasi(Request $request): array
    {
        // Dirapikan sebelum divalidasi dan disimpan: "tOYOta" menjadi "Toyota" (lihat TeksNama::judul)
        foreach (['nama', 'merk'] as $kolom) {
            if (is_string($request->input($kolom))) {
                $request->merge([$kolom => TeksNama::judul($request->input($kolom))]);
            }
        }

        $d = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'merk' => ['required', 'string', 'max:100'],
            'kursi' => ['required', 'integer', 'min:1', 'max:60'],
            'bahan_bakar' => ['required', 'in:bensin,diesel'],
            'harga_sopir_per_hari' => ['nullable', 'integer', 'min:1'],
            'harga_lepas_kunci_manual' => ['nullable', 'integer', 'min:1'],
            'harga_lepas_kunci_matic' => ['nullable', 'integer', 'min:1'],
            'foto' => ['nullable', 'array', 'max:3'],
            'foto.*' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'], // nullable: kolom foto kosong dari form dianggap tidak dikirim
        ], [
            'foto.max' => 'Foto maksimal 3 berkas.',
            'foto.*.max' => 'Setiap foto maksimal 2 MB',
            'foto.*.mimes' => 'Foto harus berformat jpg atau png',
        ]);

        // Minimal satu tarif terisi (tarif yang kosong berarti jenis sewa itu tidak ditawarkan)
        if (empty($d['harga_sopir_per_hari']) && empty($d['harga_lepas_kunci_manual']) && empty($d['harga_lepas_kunci_matic'])) {
            throw ValidationException::withMessages(['harga_sopir_per_hari' => ['Isi minimal satu tarif']]);
        }

        // Tarif yang tidak dikirim saat ubah dianggap dikosongkan: ubah mengganti seluruh data
        return $d + ['harga_sopir_per_hari' => null, 'harga_lepas_kunci_manual' => null, 'harga_lepas_kunci_matic' => null];
    }
}
