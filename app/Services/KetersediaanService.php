<?php

namespace App\Services;

use App\Models\Kendaraan;
use App\Models\Pemesanan;
use App\Models\Pengaturan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Aturan ketersediaan tipe (Spek API bagian 12 no. 1). */
class KetersediaanService
{
    /** Pesanan yang menahan unit. Menunggu pembayaran hanya selama belum lewat batas. */
    public static function pesananAktif(string $mulai, string $akhir, ?int $idTipe = null, ?string $jenisSewa = null)
    {
        return Pemesanan::query()
            ->when($idTipe, fn ($q) => $q->where('id_tipe', $idTipe))
            ->when($jenisSewa, fn ($q) => $q->where('jenis_sewa', $jenisSewa))
            ->where('tgl_mulai', '<=', $akhir)
            ->where('tgl_kembali', '>=', $mulai)
            ->where(function ($q) {
                $q->whereIn('status', ['dikonfirmasi', 'berjalan', 'pengajuan_pembatalan'])
                  ->orWhere(fn ($w) => $w->where('status', 'menunggu_pembayaran')->where('batas_pembayaran', '>', now()));
            });
    }

    /**
     * Sisa unit per tipe pada rentang [mulai, akhir]: nilai minimum di antara semua hari.
     * @return array<int,int> id_tipe => sisa_unit (tipe tanpa unit tersedia bernilai 0)
     */
    public function sisaUnit(string $mulai, string $akhir, ?int $idTipe = null): array
    {
        $kapasitas = Kendaraan::query()
            ->when($idTipe, fn ($q) => $q->where('id_tipe', $idTipe))
            ->where('status', 'tersedia')
            ->selectRaw('id_tipe, count(*) as jumlah')
            ->groupBy('id_tipe')
            ->pluck('jumlah', 'id_tipe');

        $pesanan = self::pesananAktif($mulai, $akhir, $idTipe)
            ->get(['id_tipe', 'tgl_mulai', 'tgl_kembali'])
            ->groupBy('id_tipe');

        $hari = [];
        for ($d = CarbonImmutable::parse($mulai); $d->lte($akhir); $d = $d->addDay()) {
            $hari[] = $d;
        }

        $hasil = [];
        foreach ($kapasitas as $tipe => $kap) {
            $terendah = (int) $kap;
            foreach ($hari as $d) {
                $terpakai = ($pesanan[$tipe] ?? collect())
                    ->filter(fn ($p) => $p->tgl_mulai->lte($d) && $p->tgl_kembali->gte($d))
                    ->count();
                $terendah = min($terendah, (int) $kap - $terpakai);
            }
            $hasil[$tipe] = max(0, $terendah);
        }

        return $hasil;
    }

    /**
     * Sisa sopir pada rentang (nilai minimum di antara semua hari), semua tipe dihitung bersama.
     * Pengaturan jumlah_sopir kosong berarti tidak dibatasi: kembalikan null.
     */
    public function sisaSopir(string $mulai, string $akhir): ?int
    {
        $jumlah = trim((string) Pengaturan::ambil('jumlah_sopir'));
        if ($jumlah === '') {
            return null;
        }

        $pesanan = self::pesananAktif($mulai, $akhir, null, 'dengan_sopir')->get(['tgl_mulai', 'tgl_kembali']);

        $terendah = (int) $jumlah;
        for ($d = CarbonImmutable::parse($mulai); $d->lte($akhir); $d = $d->addDay()) {
            $terpakai = $pesanan->filter(fn ($p) => $p->tgl_mulai->lte($d) && $p->tgl_kembali->gte($d))->count();
            $terendah = min($terendah, (int) $jumlah - $terpakai);
        }

        return max(0, $terendah);
    }

    /**
     * Peringatan pesanan terdampak (Spek API bagian 12 no. 8): bila kapasitas tipe turun menjadi $kapasitasBaru (unit
     * dinonaktifkan, diservis, atau dihapus), pesanan mana yang kehilangan unit? Per hari dihitung pesanan aktif tipe
     * itu (dikonfirmasi, berjalan, pengajuan pembatalan, menunggu pembayaran yang belum lewat batas) terhadap kapasitas
     * baru; yang dikembalikan adalah pesanan BELUM berjalan (belum punya plat) yang beririsan dengan hari kekurangan.
     *
     * @return Collection<int, Pemesanan> dengan relasi pelanggan
     */
    public function pesananTerdampak(int $idTipe, int $kapasitasBaru): Collection
    {
        $hariIni = now()->toDateString();

        $aktif = Pemesanan::query()->with('pelanggan:id_pelanggan,nama')
            ->where('id_tipe', $idTipe)
            ->where(fn ($q) => $q->where('tgl_kembali', '>=', $hariIni)->orWhere('status', 'berjalan'))
            ->where(function ($q) {
                $q->whereIn('status', ['dikonfirmasi', 'berjalan', 'pengajuan_pembatalan'])
                    ->orWhere(fn ($w) => $w->where('status', 'menunggu_pembayaran')->where('batas_pembayaran', '>', now()));
            })
            ->get();

        if ($aktif->isEmpty()) {
            return collect();
        }

        $awal = CarbonImmutable::parse(max($hariIni, $aktif->min(fn ($p) => $p->tgl_mulai->toDateString())));
        $akhir = CarbonImmutable::parse($aktif->max(fn ($p) => $p->tgl_kembali->toDateString()));

        $hariKurang = [];
        for ($d = $awal; $d->lte($akhir); $d = $d->addDay()) {
            if ($aktif->filter(fn ($p) => $p->tgl_mulai->lte($d) && $p->tgl_kembali->gte($d))->count() > $kapasitasBaru) {
                $hariKurang[] = $d;
            }
        }

        return $aktif->filter(fn ($p) => $p->status !== 'berjalan'
            && collect($hariKurang)->contains(fn ($d) => $p->tgl_mulai->lte($d) && $p->tgl_kembali->gte($d)))->values();
    }

    /** Daftar untuk respons 409 PESANAN_TERDAMPAK: id, nama (pelanggan), nomor, tgl_mulai, tgl_kembali. */
    public static function ringkasTerdampak(Collection $pesanan): array
    {
        return $pesanan->map(fn ($p) => [
            'id' => $p->id_pemesanan, 'nomor' => $p->nomor, 'nama' => $p->pelanggan->nama,
            'tgl_mulai' => $p->tgl_mulai->toDateString(), 'tgl_kembali' => $p->tgl_kembali->toDateString(),
        ])->all();
    }
}
