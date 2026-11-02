<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Kendaraan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class KendaraanController extends Controller
{
    public function index()
    {
        return response()->json(['data' => Kendaraan::with('detail')->orderBy('merk')->get()]);
    }

    public function show(int $id)
    {
        return response()->json(['data' => Kendaraan::with('detail')->findOrFail($id)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->aturan());

        $kendaraan = DB::transaction(function () use ($data) {
            $kendaraan = Kendaraan::create($this->kolomKendaraan($data));
            $kendaraan->detail()->create($data['detail']);

            return $kendaraan;
        });

        return response()->json([
            'message' => 'Data mobil berhasil disimpan.',
            'data' => $kendaraan->load('detail'),
        ], 201);
    }

    public function update(Request $request, int $id)
    {
        $kendaraan = Kendaraan::findOrFail($id);
        $data = $request->validate($this->aturan($kendaraan));

        DB::transaction(function () use ($kendaraan, $data) {
            $kendaraan->update($this->kolomKendaraan($data));
            $kendaraan->detail()->updateOrCreate(
                ['id_kendaraan' => $kendaraan->id_kendaraan],
                $data['detail']
            );
        });

        return response()->json([
            'message' => 'Data mobil berhasil diperbarui.',
            'data' => $kendaraan->fresh('detail'),
        ]);
    }

    public function destroy(int $id)
    {
        $kendaraan = Kendaraan::findOrFail($id);

        if ($kendaraan->pemesanan()->exists() || $kendaraan->perawatan()->exists()) {
            return response()->json([
                'message' => 'Mobil tidak bisa dihapus karena sudah punya riwayat pemesanan atau servis.',
            ], 409);
        }

        $kendaraan->delete();

        return response()->json(['message' => 'Data mobil berhasil dihapus.']);
    }

    private function aturan(?Kendaraan $kendaraan = null): array
    {
        return [
            'plat_nomor' => ['required', 'string', 'max:15',
                Rule::unique('kendaraan', 'plat_nomor')->ignore($kendaraan?->id_kendaraan, 'id_kendaraan')],
            'merk' => 'required|string|max:100',
            'tipe' => 'required|string|max:100',
            'tahun' => 'required|integer|between:1990,' . (now()->year + 1),
            'warna' => 'required|string|max:30',
            'harga_sewa_per_hari' => 'required|integer|min:0',
            'km_terakhir' => 'nullable|integer|min:0',
            'status' => ['nullable', Rule::in(['tersedia', 'disewa', 'dalam_perawatan'])],

            'detail' => 'required|array',
            'detail.nama_pemilik' => 'required|string|max:100',
            'detail.status_kepemilikan' => 'required|string|max:50',
            'detail.no_rangka' => 'required|string|max:50',
            'detail.no_mesin' => 'required|string|max:50',
            'detail.no_bpkb' => 'required|string|max:50',
            'detail.masa_berlaku_stnk' => 'required|date',
            'detail.tgl_pembelian' => 'required|date',
            'detail.harga_beli' => 'required|integer|min:0',
            'detail.catatan' => 'nullable|string',
        ];
    }

    private function kolomKendaraan(array $data): array
    {
        return collect($data)->except('detail')->all();
    }
}