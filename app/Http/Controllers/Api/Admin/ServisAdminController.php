<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ServisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** /admin/servis (Spek API bagian 10). */
class ServisAdminController extends Controller
{
    public function __construct(private ServisService $servis) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->servis->ringkasan()]);
    }

    public function statusUnit(): JsonResponse
    {
        return response()->json(['data' => $this->servis->statusUnit()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->booleanDariTeks($request, ['konfirmasi']);

        $d = $request->validate([
            'id_kendaraan' => ['required', 'integer', Rule::exists('kendaraan', 'id_kendaraan')->whereNull('deleted_at')],
            'jenis_servis' => ['required', 'string', 'max:100'],
            'tingkat_servis' => ['required', 'in:ringan,sedang,perlu_bengkel'],
            'tgl_perawatan' => ['required', 'date_format:Y-m-d'],
            'bengkel' => ['required', 'string', 'max:255'],
            'km_saat_ini' => ['nullable', 'integer', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'konfirmasi' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $this->servis->buat($request->user(), $d, $request->boolean('konfirmasi'))], 201);
    }

    /** Form multipart (komponen[] berulang). biaya_ditagih_ke_penyewa tidak diinput: diturunkan service, kiriman klien diabaikan. */
    public function selesai(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'komponen' => ['required', 'array', 'min:1'],
            'komponen.*' => ['required', 'string', 'max:255'],
            'biaya_bengkel' => ['required', 'integer', 'min:0'],
            'km_saat_ini' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json(['data' => $this->servis->selesai($request->user(), $id, $d)]);
    }

    public function batal(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->servis->batal($request->user(), $id)]);
    }

    public function interval(Request $request): JsonResponse
    {
        $d = $request->validate(['interval_km' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $this->servis->ubahInterval($request->user(), $d['interval_km'])]);
    }
}
