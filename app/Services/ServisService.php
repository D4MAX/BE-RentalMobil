<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Akun;
use App\Models\DetailPerawatan;
use App\Models\Kendaraan;
use App\Models\LogAktivitas;
use App\Models\Pemesanan;
use App\Models\Pengembalian;
use App\Models\PengembalianKondisi;
use App\Models\Pengaturan;
use App\Models\Perawatan;
use App\Models\TipeKendaraan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servis berkala (Spek API bagian 10 dan 12 no. 6). Urutan kunci sama dengan KendaraanAdminService: unit lalu tipe.
 */
class ServisService
{
    public function __construct(private KendaraanAdminService $kendaraan) {}

    public function intervalKm(): int
    {
        return max(1, (int) Pengaturan::ambil('interval_km', (string) config('rental.interval_km')));
    }

    // ------------------------------------------------------------------ catat, selesai, batal

    /** Catat servis baru: unit menjadi dalam_perawatan (hilang dari katalog) dan km_terakhir diperbarui. */
    public function buat(Akun $admin, array $d, bool $konfirmasi): array
    {
        return DB::transaction(function () use ($admin, $d, $konfirmasi) {
            $unit = Kendaraan::query()->lockForUpdate()->find($d['id_kendaraan']) ?? throw $this->tidakDitemukan('Unit');
            TipeKendaraan::query()->lockForUpdate()->find($unit->id_tipe);

            if (Pemesanan::where('id_kendaraan', $unit->id_kendaraan)->where('status', 'berjalan')->exists()) {
                throw new ApiException('Unit sedang disewa, perawatan ditunda', 'UNIT_SEDANG_DISEWA');
            }
            if ($unit->status === 'dalam_perawatan' || Perawatan::where('id_kendaraan', $unit->id_kendaraan)->where('status', 'berlangsung')->exists()) {
                throw new ApiException('Unit sudah dalam servis', 'UNIT_SUDAH_DALAM_SERVIS');
            }
            if (isset($d['km_saat_ini']) && (int) $d['km_saat_ini'] < (int) $unit->km_terakhir) {
                throw ValidationException::withMessages(['km_saat_ini' => ["Kilometer tidak boleh lebih kecil dari sebelumnya ({$unit->km_terakhir})"]]);
            }
            if ($unit->status === 'tersedia') {
                $this->kendaraan->periksaTerdampak($unit, $konfirmasi); // unit yang sudah tidak tersedia tidak mengurangi kapasitas lagi
            }

            $servis = Perawatan::create([
                'id_kendaraan' => $unit->id_kendaraan, 'jenis_servis' => $d['jenis_servis'], 'tingkat_servis' => $d['tingkat_servis'],
                'tgl_perawatan' => $d['tgl_perawatan'], 'km_saat_ini' => $d['km_saat_ini'] ?? null, 'bengkel' => $d['bengkel'],
                'catatan' => $d['catatan'] ?? null, 'status' => 'berlangsung',
            ]);
            $unit->update(['status' => 'dalam_perawatan', 'km_terakhir' => max((int) $unit->km_terakhir, (int) ($d['km_saat_ini'] ?? 0))]);
            LogAktivitas::catat("Servis dicatat untuk {$unit->plat_nomor}: {$d['jenis_servis']}".($konfirmasi ? ' (dengan konfirmasi pesanan terdampak)' : ''), $admin, 'perawatan', $servis->id_perawatan);

            return ['id' => $servis->id_perawatan, 'status' => 'berlangsung'];
        });
    }

    /**
     * Selesai: unit tersedia lagi, km_berikutnya = km_saat_ini + interval_km, tgl_berikutnya = hari ini + interval_bulan
     * (6 bulan), biaya_ditanggung_perusahaan = max(0, biaya_bengkel - biaya_ditagih_ke_penyewa).
     * biaya_ditagih_ke_penyewa diturunkan dari pengembalian (taksiran kerusakan), bukan input admin.
     */
    public function selesai(Akun $admin, int $id, array $d): array
    {
        return DB::transaction(function () use ($admin, $id, $d) {
            $servis = Perawatan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Servis');
            $unit = Kendaraan::query()->lockForUpdate()->find($servis->id_kendaraan);

            if ($servis->status !== 'berlangsung') {
                throw new ApiException('Servis ini tidak sedang berlangsung', 'STATUS_TIDAK_VALID');
            }
            if ((int) $d['km_saat_ini'] < (int) $unit->km_terakhir) {
                throw ValidationException::withMessages(['km_saat_ini' => ["Kilometer tidak boleh lebih kecil dari sebelumnya ({$unit->km_terakhir})"]]);
            }

            $ditagih = $this->taksiranKerusakan($servis->id_pemesanan_asal);
            $kmBerikutnya = (int) $d['km_saat_ini'] + $this->intervalKm();
            $tglBerikutnya = now()->addMonthsNoOverflow((int) config('rental.interval_bulan'))->toDateString();

            foreach ($d['komponen'] as $komponen) {
                DetailPerawatan::create(['id_perawatan' => $id, 'komponen' => $komponen]);
            }
            $servis->update([
                'status' => 'selesai', 'biaya_bengkel' => (int) $d['biaya_bengkel'], 'biaya_ditagih_ke_penyewa' => $ditagih,
                'km_saat_ini' => (int) $d['km_saat_ini'], 'km_berikutnya' => $kmBerikutnya, 'tgl_berikutnya' => $tglBerikutnya,
            ]);
            $unit->update(['status' => 'tersedia', 'km_terakhir' => (int) $d['km_saat_ini']]);
            LogAktivitas::catat("Servis selesai untuk {$unit->plat_nomor}", $admin, 'perawatan', $id);

            return ['status' => 'selesai', 'km_berikutnya' => $kmBerikutnya, 'tgl_berikutnya' => $tglBerikutnya, 'biaya_ditanggung_perusahaan' => $this->ditanggungPerusahaan((int) $d['biaya_bengkel'], $ditagih)];
        });
    }

    /**
     * Taksiran biaya kerusakan dari pengembalian pesanan asal (rumus sama dengan AdminPemesananService::ringkasan:
     * jumlah biaya kondisi selain baik). Bukan jumlah yang sudah dibayar penyewa. Servis manual: 0.
     */
    private function taksiranKerusakan(?int $idPemesananAsal): int
    {
        if (! $idPemesananAsal) {
            return 0;
        }

        return (int) PengembalianKondisi::query()->where('kondisi', '!=', 'baik')
            ->whereIn('id_pengembalian', Pengembalian::where('id_pemesanan', $idPemesananAsal)->select('id_pengembalian'))->sum('biaya');
    }

    /** Bagian biaya bengkel yang tidak ditagih ke penyewa; tidak pernah negatif (kelebihan tagihan bukan beban perusahaan). */
    private function ditanggungPerusahaan(int $biayaBengkel, int $ditagih): int
    {
        return max(0, $biayaBengkel - $ditagih);
    }

    public function batal(Akun $admin, int $id): array
    {
        return DB::transaction(function () use ($admin, $id) {
            $servis = Perawatan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Servis');
            $unit = Kendaraan::query()->lockForUpdate()->find($servis->id_kendaraan);

            if ($servis->status !== 'berlangsung') {
                throw new ApiException('Servis ini tidak sedang berlangsung', 'STATUS_TIDAK_VALID');
            }

            $servis->update(['status' => 'dibatalkan']);
            $unit->update(['status' => 'tersedia']);
            LogAktivitas::catat("Servis dibatalkan untuk {$unit->plat_nomor}", $admin, 'perawatan', $id);

            return ['status' => 'dibatalkan'];
        });
    }

    /** Interval km baru; status pengingat dihitung ulang dari servis selesai terakhir tiap unit. */
    public function ubahInterval(Akun $admin, int $intervalKm): array
    {
        return DB::transaction(function () use ($admin, $intervalKm) {
            Pengaturan::updateOrCreate(['kunci' => 'interval_km'], ['nilai' => (string) $intervalKm]);

            Perawatan::query()->where('status', 'selesai')->orderBy('id_perawatan')->get()->keyBy('id_kendaraan')
                ->each(fn ($s) => $s->update(['km_berikutnya' => (int) $s->km_saat_ini + $intervalKm]));

            LogAktivitas::catat("Interval servis diubah menjadi {$intervalKm} km", $admin, 'pengaturan', null);

            return ['interval_km' => $intervalKm];
        });
    }

    // ------------------------------------------------------------------ baca

    /** GET /admin/servis: ringkasan, servis sedang berlangsung, dan riwayat (selesai dan dibatalkan). */
    public function ringkasan(): array
    {
        $dengan = ['kendaraan.tipe:id_tipe,nama', 'detail', 'pemesananAsal:id_pemesanan'];
        $selesai = Perawatan::query()->where('status', 'selesai');

        return [
            'ringkasan' => [
                'unit_dalam_servis' => Kendaraan::where('status', 'dalam_perawatan')->count(),
                'unit_tersedia' => Kendaraan::where('status', 'tersedia')->count(),
                'total_biaya_bengkel' => (int) (clone $selesai)->sum('biaya_bengkel'),
                'total_ditagih' => (int) (clone $selesai)->sum('biaya_ditagih_ke_penyewa'),
                // Jumlah nilai per servis selesai (bukan selisih total), sehingga tidak ada nilai negatif yang saling menutupi
                'total_ditanggung_perusahaan' => (int) (clone $selesai)->get(['biaya_bengkel', 'biaya_ditagih_ke_penyewa'])
                    ->sum(fn ($s) => $this->ditanggungPerusahaan((int) $s->biaya_bengkel, (int) $s->biaya_ditagih_ke_penyewa)),
            ],
            'sedang_servis' => Perawatan::with($dengan)->where('status', 'berlangsung')->orderBy('tgl_perawatan')->get()
                ->map(fn ($s) => $this->bentuk($s, false))->values()->all(),
            'riwayat' => Perawatan::with($dengan)->whereIn('status', ['selesai', 'dibatalkan'])->orderByDesc('id_perawatan')->limit(200)->get()
                ->map(fn ($s) => $this->bentuk($s, true))->values()->all(),
        ];
    }

    private function bentuk(Perawatan $s, bool $riwayat): array
    {
        $unit = $s->kendaraan;
        $dasar = [
            'id' => $s->id_perawatan, 'id_kendaraan' => $s->id_kendaraan, 'plat_nomor' => $unit->plat_nomor,
            'unit' => trim("{$unit->tipe->nama} {$unit->plat_nomor}"), 'tgl_perawatan' => $s->tgl_perawatan->toDateString(),
            'jenis_servis' => $s->jenis_servis, 'tingkat_servis' => $s->tingkat_servis, 'bengkel' => $s->bengkel,
            'asal_pesanan' => $s->pemesananAsal?->nomor, // servis dari pengembalian memuat nomor pesanan asalnya
        ];
        if (! $riwayat) {
            // Belum selesai: biaya bengkel belum diketahui (kolom baru terisi saat selesai)
            return $dasar + ['biaya_bengkel' => null, 'biaya_ditanggung_perusahaan' => null];
        }

        return $dasar + [
            'status' => $s->status, 'biaya_bengkel' => $s->status === 'selesai' ? (int) $s->biaya_bengkel : null, 'biaya_ditagih_ke_penyewa' => (int) $s->biaya_ditagih_ke_penyewa,
            'biaya_ditanggung_perusahaan' => $s->status === 'selesai'
                ? $this->ditanggungPerusahaan((int) $s->biaya_bengkel, (int) $s->biaya_ditagih_ke_penyewa) : null,
            'komponen' => $s->detail->pluck('komponen')->values()->all(), 'km_saat_ini' => $s->km_saat_ini,
            'km_berikutnya' => $s->km_berikutnya, 'tgl_berikutnya' => $s->tgl_berikutnya?->toDateString(),
        ];
    }

    /**
     * Pengingat berkala per unit: perlu_servis bila km_terakhir >= km_berikutnya atau hari ini >= tgl_berikutnya; segera
     * bila sisa km <= 1000 atau sisa hari <= 30; aman selain itu. Patokan: servis selesai terakhir; bila unit belum
     * pernah diservis, km_berikutnya = km_awal + interval km dan tgl_berikutnya = tanggal unit dibuat + interval bulan.
     */
    public function statusUnit(): array
    {
        $terakhir = Perawatan::query()->where('status', 'selesai')->orderBy('id_perawatan')->get()->keyBy('id_kendaraan');
        $hariIni = now()->startOfDay();

        return Kendaraan::query()->with('tipe:id_tipe,nama')->orderBy('plat_nomor')->get()->map(function ($u) use ($terakhir, $hariIni) {
            $dasar = ['id_kendaraan' => $u->id_kendaraan, 'plat_nomor' => $u->plat_nomor, 'unit' => trim("{$u->tipe->nama} {$u->plat_nomor}"),
                'status_unit' => $u->status, 'km_terakhir' => $u->km_terakhir];
            $s = $terakhir->get($u->id_kendaraan);
            $kmAwal = (int) ($u->km_awal ?? $u->km_terakhir);
            $kmBerikutnya = $s ? (int) $s->km_berikutnya : $kmAwal + $this->intervalKm();
            $tglBerikutnya = $s ? $s->tgl_berikutnya : $u->created_at?->copy()->addMonthsNoOverflow((int) config('rental.interval_bulan'));

            $sisaKm = $kmBerikutnya - (int) $u->km_terakhir;
            $sisaHari = $tglBerikutnya ? (int) round($hariIni->diffInDays($tglBerikutnya->copy()->startOfDay(), false)) : null;
            $status = match (true) {
                $sisaKm <= 0 || ($sisaHari !== null && $sisaHari <= 0) => 'perlu_servis',
                $sisaKm <= 1000 || ($sisaHari !== null && $sisaHari <= 30) => 'segera',
                default => 'aman',
            };

            return $dasar + [
                'servis_terakhir' => $s?->tgl_perawatan->toDateString(),
                'km_sejak_servis' => (int) $u->km_terakhir - ($s ? (int) $s->km_saat_ini : $kmAwal),
                'km_berikutnya' => $kmBerikutnya, 'tgl_berikutnya' => $tglBerikutnya?->toDateString(),
                'status' => $status, 'sisa_km' => $sisaKm, 'sisa_hari' => $sisaHari,
            ];
        })->values()->all();
    }

    /** Untuk dashboard: unit yang segera atau perlu servis dan belum sedang diservis. */
    public function pengingat(): array
    {
        return collect($this->statusUnit())
            ->filter(fn ($u) => in_array($u['status'], ['segera', 'perlu_servis'], true) && $u['status_unit'] !== 'dalam_perawatan')
            ->map(fn ($u) => ['id_kendaraan' => $u['id_kendaraan'], 'plat_nomor' => $u['plat_nomor'], 'status' => $u['status']])
            ->values()->all();
    }

    private function tidakDitemukan(string $apa): ApiException
    {
        return new ApiException("{$apa} tidak ditemukan", 'TIDAK_DITEMUKAN', 404);
    }
}
