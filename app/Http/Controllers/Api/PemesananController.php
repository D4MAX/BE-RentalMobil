<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AjukanPembatalanRequest;
use App\Exceptions\ApiException;
use App\Http\Requests\Api\PemesananRequest;
use App\Http\Requests\Api\ReviewRequest;
use App\Http\Resources\PemesananUserResource;
use App\Models\Pemesanan;
use App\Services\DokumenPdfService;
use App\Services\PemesananService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PemesananController extends Controller
{
    private const TAB = [
        'berlangsung' => ['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'pengajuan_pembatalan'],
        'riwayat' => ['selesai', 'dibatalkan'],
    ];

    public function __construct(private PemesananService $pemesanan) {}

    public function store(PemesananRequest $request): JsonResponse
    {
        $akun = $request->user();

        return response()->json(['data' => $this->pemesanan->buat($akun, $akun->pelanggan, $request->validated())], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tab' => ['nullable', 'in:berlangsung,riwayat'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $hasil = Pemesanan::query()
            ->where('id_pelanggan', $request->user()->pelanggan->id_pelanggan)
            ->when($d['tab'] ?? null, fn ($q, $tab) => $q->whereIn('status', self::TAB[$tab]))
            ->with(['tipe:id_tipe,nama', 'review:id_review,id_pemesanan'])
            ->orderByDesc('id_pemesanan')
            ->paginate($d['per_page'] ?? 10, ['*'], 'page', $d['page'] ?? 1);

        return response()->json([
            'data' => PemesananUserResource::collection($hasil->getCollection())->resolve(),
            'meta' => ['page' => $hasil->currentPage(), 'per_page' => $hasil->perPage(), 'total' => $hasil->total()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $pemesanan = Pemesanan::query()
            ->where('id_pelanggan', $request->user()->pelanggan->id_pelanggan) // milik sendiri; selain itu 404
            ->with(['tipe:id_tipe,nama', 'review:id_review,id_pemesanan', 'kendaraan:id_kendaraan,plat_nomor', 'pembayaran'])
            ->findOrFail($id);

        return response()->json(['data' => PemesananUserResource::detail($pemesanan)->resolve()]);
    }

    public function bayar(Request $request, int $id): JsonResponse
    {
        $akun = $request->user();

        return response()->json(['data' => $this->pemesanan->bayarLagi($akun, $akun->pelanggan, $id)]);
    }

    public function batalkan(Request $request, int $id): JsonResponse
    {
        $akun = $request->user();

        return response()->json(['data' => $this->pemesanan->batalkan($akun, $akun->pelanggan, $id)]);
    }

    public function ajukanPembatalan(AjukanPembatalanRequest $request, int $id): JsonResponse
    {
        $akun = $request->user();

        return response()->json(['data' => $this->pemesanan->ajukanPembatalan($akun, $akun->pelanggan, $id, $request->validated())]);
    }

    /** Nota PDF: dikonfirmasi ke atas (sama dengan tombol bisa_unduh_nota). */
    public function nota(Request $request, int $id, DokumenPdfService $dokumen)
    {
        $pemesanan = Pemesanan::query()->where('id_pelanggan', $request->user()->pelanggan->id_pelanggan)->findOrFail($id);
        if (! $pemesanan->aksiUser()['bisa_unduh_nota']) {
            throw new ApiException('Pesanan belum dibayar', 'STATUS_TIDAK_VALID');
        }

        return response($dokumen->nota($pemesanan), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="nota-'.$pemesanan->nomor.'.pdf"',
        ]);
    }

    public function review(ReviewRequest $request, int $id): JsonResponse
    {
        $akun = $request->user();

        return response()->json(['data' => $this->pemesanan->beriReview($akun, $akun->pelanggan, $id, $request->validated())], 201);
    }
}
