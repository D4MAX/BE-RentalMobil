<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pemesanan;
use App\Models\Review;
use App\Services\PemesananService;
use Illuminate\Http\Request;

class PemesananController extends Controller
{
    public function __construct(private PemesananService $service)
    {
    }

    /** Pesan mobil: input tanggal sewa + pembayaran */
    public function store(Request $request)
    {
        $pelanggan = $request->user()->pelanggan;

        if (! $pelanggan) {
            return response()->json(['message' => 'Akun ini tidak punya data pelanggan.'], 422);
        }

        $data = $request->validate([
            'id_kendaraan' => 'required|integer|exists:kendaraan,id_kendaraan',
            'tgl_mulai' => 'required|date|after_or_equal:today',
            'tgl_selesai' => 'required|date|after_or_equal:tgl_mulai',
            'metode' => 'required|string|max:50',
            'jumlah' => 'required|integer|min:1',
        ]);

        $pemesanan = $this->service->buat($data, $pelanggan->id_pelanggan);

        return response()->json([
            'message' => 'Pemesanan berhasil. Nota terlampir.',
            'data' => $pemesanan,
        ], 201);
    }

    /** Riwayat transaksi milik user */
    public function index(Request $request)
    {
        $riwayat = Pemesanan::with(['kendaraan:id_kendaraan,merk,tipe,plat_nomor', 'pembayaran', 'pengembalian', 'review'])
            ->where('id_pelanggan', $request->user()->pelanggan?->id_pelanggan)
            ->latest('id_pemesanan')
            ->get();

        return response()->json(['data' => $riwayat]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json([
            'data' => $this->milikUser($request, $id, ['kendaraan', 'pembayaran', 'pengembalian', 'review']),
        ]);
    }

    /**
     * Ajukan pembatalan: status jadi "pengajuan_batal" + link WhatsApp.
     * Pembatalan final diproses admin.
     */
    public function batal(Request $request, int $id)
    {
        $pemesanan = $this->milikUser($request, $id, ['pembayaran']);

        $bisaDiajukan = in_array($pemesanan->status, PemesananService::STATUS_AKTIF, true)
            && now()->startOfDay()->lt($pemesanan->tgl_mulai);

        if (! $bisaDiajukan) {
            return response()->json(['message' => 'Pesanan tidak bisa dibatalkan.'], 422);
        }

        if ($pemesanan->status !== 'pengajuan_batal') {
            $pemesanan->update(['status' => 'pengajuan_batal']);
        }

        $pesan = "Halo admin, saya ingin membatalkan pemesanan dengan ID #{$pemesanan->id_pemesanan} "
            . "dan mengajukan pengembalian dana sebesar Rp " . number_format($pemesanan->total_biaya, 0, ',', '.') . '.';

        return response()->json([
            'message' => 'Pengajuan pembatalan tercatat. Lanjutkan ke WhatsApp admin untuk pengembalian dana.',
            'link_whatsapp' => 'https://wa.me/' . config('rental.wa_admin') . '?text=' . rawurlencode($pesan),
        ]);
    }

    public function review(Request $request, int $id)
    {
        $pemesanan = $this->milikUser($request, $id, ['review']);

        if ($pemesanan->status !== 'selesai') {
            return response()->json(['message' => 'Review hanya untuk pesanan yang sudah selesai.'], 422);
        }

        if ($pemesanan->review) {
            return response()->json(['message' => 'Pesanan ini sudah di-review.'], 422);
        }

        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'komentar' => 'nullable|string|max:1000',
        ]);

        $review = Review::create([
            'id_pemesanan' => $pemesanan->id_pemesanan,
            'rating' => $data['rating'],
            'komentar' => $data['komentar'] ?? null,
            'tgl_review' => now()->toDateString(),
        ]);

        return response()->json(['message' => 'Terima kasih atas review Anda.', 'data' => $review], 201);
    }

    private function milikUser(Request $request, int $id, array $with = []): Pemesanan
    {
        return Pemesanan::with($with)
            ->where('id_pelanggan', $request->user()->pelanggan?->id_pelanggan)
            ->findOrFail($id);
    }
}