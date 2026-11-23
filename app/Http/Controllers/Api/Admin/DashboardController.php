<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Kendaraan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Services\ServisService;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/dashboard. tugas_admin berisi (urut): pengajuan pembatalan, refund yang harus ditransfer, serahkan unit
 * (mulai hari ini atau besok), pengembalian jatuh tempo, tagihan belum dibayar, dan jaminan siap dikembalikan.
 * Tanggal menurut zona aplikasi (Asia/Jakarta). pengingat_servis: unit yang segera atau perlu servis.
 */
class DashboardController extends Controller
{
    public function __construct(private ServisService $servis) {}

    public function __invoke(): JsonResponse
    {
        $hitung = Pemesanan::query()->selectRaw('status, count(*) as jumlah')->groupBy('status')->pluck('jumlah', 'status');
        $unit = Kendaraan::query()->selectRaw('status, count(*) as jumlah')->groupBy('status')->pluck('jumlah', 'status');

        $tugas = [
            ...$this->pengajuanPembatalan(),
            ...$this->refundTransfer(),
            ...$this->serahkanUnit(),
            ...$this->pengembalianJatuhTempo(),
            ...$this->tagihanBelumDibayar(),
            ...$this->jaminanSiapDikembalikan(),
        ];

        return response()->json(['data' => [
            'hitungan' => [
                'menunggu_pembayaran' => (int) ($hitung['menunggu_pembayaran'] ?? 0),
                'dikonfirmasi' => (int) ($hitung['dikonfirmasi'] ?? 0),
                'berjalan' => (int) ($hitung['berjalan'] ?? 0),
                'pengajuan_pembatalan' => (int) ($hitung['pengajuan_pembatalan'] ?? 0),
                'unit_tersedia' => (int) ($unit['tersedia'] ?? 0),
                'unit_dalam_servis' => (int) ($unit['dalam_perawatan'] ?? 0),
            ],
            'tugas_admin' => $tugas,
            'pengingat_servis' => $this->servis->pengingat(),
        ]]);
    }

    private function tugas(Pemesanan $p, string $jenis, string $keterangan, array $tambahan = []): array
    {
        return ['id' => $p->id_pemesanan, 'jenis' => $jenis, 'keterangan' => $keterangan] + $tambahan;
    }

    private function rupiah(int|string $nominal): string
    {
        return 'Rp'.number_format((int) $nominal, 0, ',', '.');
    }

    private function pengajuanPembatalan(): array
    {
        return Pemesanan::query()->with('pelanggan:id_pelanggan,nama')->where('status', 'pengajuan_pembatalan')
            ->orderBy('id_pemesanan')->get()
            ->map(fn ($p) => $this->tugas($p, 'pengajuan_pembatalan', "Pengajuan pembatalan {$p->nomor} dari {$p->pelanggan->nama}"))
            ->all();
    }

    /**
     * Semua pembayaran yang uangnya harus ditransfer admin dan belum selesai, apa pun status pesanannya: pesanan
     * dibatalkan, juga kelebihan bayar pada pesanan yang tetap berjalan. Tanpa rekening (QRIS yang gagal di API atau
     * channel tak dikenal): hubungi pelanggan di luar sistem dan tulis hasilnya di catatan admin.
     */
    private function refundTransfer(): array
    {
        return Pembayaran::query()->with('pemesanan.pelanggan:id_pelanggan,nama,no_hp')
            ->where('jenis', 'sewa')->where('status', 'lunas')->where('refund_cara', 'transfer_admin')->where('refund_selesai', false)
            ->orderBy('id_pembayaran')->get()
            ->map(function ($b) {
                $p = $b->pemesanan;
                $jumlah = $this->rupiah($b->jumlah);
                $sebab = $p->status === 'dibatalkan' ? "pesanan {$p->nomor} dibatalkan" : "kelebihan bayar pada {$p->nomor} (pesanan {$p->status})";
                $tujuan = $b->no_rekening
                    ? "ke {$b->bank} {$b->no_rekening} a.n. {$b->nama_rekening}"
                    : "belum ada rekening: hubungi {$p->pelanggan->nama} ({$p->pelanggan->no_hp}) di luar sistem dan catat hasilnya di catatan admin";

                return $this->tugas($p, 'refund_transfer', "Transfer refund {$jumlah}, {$sebab}, {$tujuan}", ['id_pembayaran' => $b->id_pembayaran]);
            })->all();
    }

    private function serahkanUnit(): array
    {
        $hariIni = now()->toDateString();

        return Pemesanan::query()->with(['pelanggan:id_pelanggan,nama', 'tipe:id_tipe,nama'])
            ->where('status', 'dikonfirmasi')->whereBetween('tgl_mulai', [$hariIni, now()->addDay()->toDateString()])
            ->orderBy('tgl_mulai')->orderBy('jam_serah')->get()
            ->map(function ($p) use ($hariIni) {
                $kapan = $p->tgl_mulai->toDateString() === $hariIni ? 'hari ini' : 'besok';

                return $this->tugas($p, 'serahkan_unit', "Serahkan {$p->tipe->nama} ({$p->nomor}) ke {$p->pelanggan->nama} {$kapan} jam ".substr((string) $p->jam_serah, 0, 5));
            })->all();
    }

    private function pengembalianJatuhTempo(): array
    {
        $hariIni = now()->startOfDay();

        return Pemesanan::query()->with(['pelanggan:id_pelanggan,nama', 'kendaraan:id_kendaraan,plat_nomor'])
            ->where('status', 'berjalan')->where('tgl_kembali', '<=', $hariIni->toDateString())
            ->orderBy('tgl_kembali')->get()
            ->map(function ($p) use ($hariIni) {
                $terlambat = (int) round($p->tgl_kembali->startOfDay()->diffInDays($hariIni, false));
                $status = $terlambat > 0 ? "terlambat {$terlambat} hari" : 'jatuh tempo hari ini';

                return $this->tugas($p, 'pengembalian_jatuh_tempo', "Pengembalian {$p->nomor} ({$p->kendaraan?->plat_nomor}) dari {$p->pelanggan->nama} {$status}");
            })->all();
    }

    private function tagihanBelumDibayar(): array
    {
        return Pembayaran::query()->with('pemesanan.pelanggan:id_pelanggan,nama')
            ->where('jenis', 'tambahan')->where('status', 'menunggu')
            ->orderBy('id_pembayaran')->get()
            ->map(fn ($b) => $this->tugas($b->pemesanan, 'tagihan_belum_dibayar',
                "Tagihan tambahan {$this->rupiah($b->jumlah)} dari {$b->pemesanan->pelanggan->nama} untuk {$b->pemesanan->nomor} belum dibayar",
                ['id_pembayaran' => $b->id_pembayaran]))
            ->all();
    }

    /** Pesanan selesai (lepas kunci) dengan jaminan masih ditahan dan tanpa tagihan tambahan yang belum lunas. */
    private function jaminanSiapDikembalikan(): array
    {
        return Pemesanan::query()->with('pelanggan:id_pelanggan,nama')
            ->where('status', 'selesai')->where('jenis_sewa', 'lepas_kunci')
            ->whereHas('jaminan', fn ($q) => $q->where('dikembalikan', false))
            ->whereDoesntHave('pembayaran', fn ($q) => $q->where('jenis', 'tambahan')->where('status', 'menunggu'))
            ->orderBy('id_pemesanan')->get()
            ->map(fn ($p) => $this->tugas($p, 'jaminan_siap_dikembalikan', "Jaminan {$p->nomor} siap dikembalikan ke {$p->pelanggan->nama}"))
            ->all();
    }
}
