<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\KendaraanAdminService;
use App\Services\SampahService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tempat sampah tipe dan unit (soft delete). GET /admin/sampah, pulihkan, dan hapus permanen satu data
 * (konfirmasi=true wajib; hanya untuk data yang sudah di tempat sampah). Pembuangan otomatis: php artisan sampah:bersihkan.
 */
class SampahAdminController extends Controller
{
    public function __construct(private SampahService $sampah, private KendaraanAdminService $kendaraan) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->sampah->daftarSampah()]);
    }

    public function pulihkanTipe(Request $request, int $id): JsonResponse
    {
        $tipe = $this->sampah->pulihkanTipe($request->user(), $id);

        return response()->json(['data' => $this->kendaraan->bentukTipe($tipe)]);
    }

    public function pulihkanUnit(Request $request, int $id): JsonResponse
    {
        $unit = $this->sampah->pulihkanUnit($request->user(), $id);

        return response()->json(['data' => $this->kendaraan->bentukUnit($unit)]);
    }

    /** konfirmasi=true (query atau body) wajib. */
    public function hapusPermanenTipe(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->sampah->hapusPermanenTipe($request->user(), $id, $request->boolean('konfirmasi'))]);
    }

    public function hapusPermanenUnit(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->sampah->hapusPermanenUnit($request->user(), $id, $request->boolean('konfirmasi'))]);
    }
}
