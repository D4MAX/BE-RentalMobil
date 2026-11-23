<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PengembalianRequest;
use App\Http\Requests\Api\SerahTerimaRequest;
use App\Http\Resources\PemesananAdminResource;
use App\Models\Pemesanan;
use App\Services\AdminPemesananService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Semua rute di sini berada di bawah middleware peran:admin. */
class PemesananAdminController extends Controller
{
    private const STATUS = ['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'selesai', 'pengajuan_pembatalan', 'dibatalkan'];

    public function __construct(private AdminPemesananService $admin) {}

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', self::STATUS)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = trim((string) ($d['q'] ?? ''));
        $hasil = Pemesanan::query()
            ->with(['pelanggan:id_pelanggan,nama', 'tipe:id_tipe,nama', 'kendaraan:id_kendaraan,plat_nomor'])
            ->when($d['status'] ?? null, fn ($w, $s) => $w->where('status', $s))
            ->when($q !== '', function ($w) use ($q) {
                // Nomor ("PSN-0015", "15") atau nama pelanggan
                $w->where(function ($x) use ($q) {
                    $like = '%'.addcslashes($q, '%_\\').'%';
                    $x->whereHas('pelanggan', fn ($p) => $p->where('nama', 'like', $like));
                    if (preg_match('/^(?:PSN-?)?0*(\d+)$/i', $q, $m)) {
                        $x->orWhere('id_pemesanan', (int) $m[1]);
                    }
                });
            })
            ->orderByDesc('id_pemesanan')
            ->paginate($d['per_page'] ?? 10, ['*'], 'page', $d['page'] ?? 1);

        return response()->json([
            'data' => $hasil->getCollection()->map(fn ($p) => PemesananAdminResource::ringkas($p))->values()->all(),
            'meta' => ['page' => $hasil->currentPage(), 'per_page' => $hasil->perPage(), 'total' => $hasil->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $p = Pemesanan::query()->with([
            'pelanggan.akun', 'tipe:id_tipe,nama', 'kendaraan:id_kendaraan,plat_nomor', 'pembayaran', 'review',
            'serahTerima.kendaraan:id_kendaraan,plat_nomor', 'serahTerima.admin.pelanggan', 'jaminan',
            'pengembalian.kondisi', 'pengembalian.biayaTambahan', 'pengembalian.foto',
        ])->find($id) ?? throw new ApiException('Pesanan tidak ditemukan', 'TIDAK_DITEMUKAN', 404);

        return response()->json(['data' => PemesananAdminResource::detail($p)]);
    }

    public function catatan(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['catatan_admin' => ['present', 'nullable', 'string', 'max:2000']]);

        return response()->json(['data' => $this->admin->simpanCatatan($request->user(), $id, $d['catatan_admin'])]);
    }

    public function unitTersedia(int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->unitTersedia($id)]);
    }

    public function serahTerima(SerahTerimaRequest $request, int $id): JsonResponse
    {
        $d = $request->validated();

        return response()->json(['data' => $this->admin->serahkan($request->user(), $id, $d, $request->file('foto_kondisi', []))]);
    }

    public function previewPengembalian(PengembalianRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->previewPengembalian($id, $request->validated())]);
    }

    public function pengembalian(PengembalianRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->selesaikan($request->user(), $id, $request->validated(), $request->file('foto', []))]);
    }

    public function setujuiPembatalan(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->setujuiPembatalan($request->user(), $id)]);
    }

    public function tolakPembatalan(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->tolakPembatalan($request->user(), $id)]);
    }

    public function refundSelesai(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['id_pembayaran' => ['nullable', 'integer']]);

        return response()->json(['data' => $this->admin->refundSelesai($request->user(), $id, $d['id_pembayaran'] ?? null)]);
    }

    public function tagihanLunas(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['metode' => ['nullable', 'in:tunai,transfer']]);

        return response()->json(['data' => $this->admin->tandaiTagihanLunas($request->user(), $id, $d['metode'] ?? null)]);
    }

    public function jaminanKembalikan(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->admin->kembalikanJaminan($request->user(), $id)]);
    }
}
