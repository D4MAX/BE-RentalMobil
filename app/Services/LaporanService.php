<?php

namespace App\Services;

use App\Models\Pemesanan;
use Carbon\CarbonImmutable;

/**
 * Laporan transaksi (Spek API bagian 11). Periode dihitung dari tgl_mulai pesanan, tanggal menurut zona aplikasi.
 * total_pendapatan hanya menjumlah pesanan dikonfirmasi, berjalan, dan selesai (biaya sewa + tagihan tambahan).
 */
class LaporanService
{
    public const PERIODE = ['7', '30', 'bulan_ini', 'bulan_lalu'];

    public const STATUS_PENDAPATAN = ['dikonfirmasi', 'berjalan', 'selesai'];

    private const URUTAN_STATUS = ['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'selesai', 'pengajuan_pembatalan', 'dibatalkan'];

    /**
     * 7 (bawaan) dan 30: sampai hari ini, 7 atau 30 hari termasuk hari ini. bulan_ini: tanggal 1 sampai hari ini.
     * bulan_lalu: seluruh bulan lalu.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rentang(string $periode): array
    {
        $hariIni = CarbonImmutable::now()->startOfDay();

        return match ($periode) {
            '30' => [$hariIni->subDays(29), $hariIni],
            'bulan_ini' => [$hariIni->startOfMonth(), $hariIni],
            'bulan_lalu' => [$hariIni->subMonthNoOverflow()->startOfMonth(), $hariIni->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            default => [$hariIni->subDays(6), $hariIni],
        };
    }

    /** @return array{periode: array{dari: string, sampai: string}, ringkasan: array, data: list<array>} */
    public function susun(string $periode = '7', ?string $status = null): array
    {
        [$dari, $sampai] = $this->rentang($periode);

        $baris = Pemesanan::query()
            ->with(['pelanggan:id_pelanggan,nama', 'tipe:id_tipe,nama', 'kendaraan:id_kendaraan,plat_nomor'])
            ->withSum(['pembayaran as tagihan_tambahan' => fn ($q) => $q->where('jenis', 'tambahan')->where('status', 'lunas')], 'jumlah')
            ->whereBetween('tgl_mulai', [$dari->toDateString(), $sampai->toDateString()])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('tgl_mulai')->orderBy('id_pemesanan')
            ->get()
            ->map(fn ($p) => [
                'tgl_mulai' => $p->tgl_mulai->toDateString(), 'nomor' => $p->nomor, 'pelanggan' => $p->pelanggan->nama,
                'unit' => trim($p->tipe->nama.' '.($p->kendaraan?->plat_nomor ?? '')), 'status' => $p->status,
                'sewa' => (int) $p->total_biaya, 'tagihan_tambahan' => (int) ($p->tagihan_tambahan ?? 0),
            ]);

        $perStatus = $baris->countBy('status');

        return [
            'periode' => ['dari' => $dari->toDateString(), 'sampai' => $sampai->toDateString()],
            'ringkasan' => [
                'total_pendapatan' => (int) $baris->whereIn('status', self::STATUS_PENDAPATAN)->sum(fn ($b) => $b['sewa'] + $b['tagihan_tambahan']),
                'jumlah_transaksi' => $baris->count(),
                'jumlah_dibatalkan' => (int) ($perStatus['dibatalkan'] ?? 0),
                'per_status' => collect(self::URUTAN_STATUS)->filter(fn ($s) => $perStatus->has($s))->mapWithKeys(fn ($s) => [$s => $perStatus[$s]])->all(),
            ],
            'data' => $baris->values()->all(),
        ];
    }
}
