<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Akun;
use App\Models\DetailKendaraan;
use App\Models\Kendaraan;
use App\Models\LogAktivitas;
use App\Models\Pemesanan;
use App\Models\Perawatan;
use App\Models\TipeFoto;
use App\Models\TipeKendaraan;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tempat sampah tipe dan unit (soft delete): daftar, pulihkan, hapus permanen, dan pembuangan otomatis (sampah:bersihkan).
 * Urutan kunci sama dengan KendaraanAdminService: unit lalu tipe, dan semua kunci diambil sebelum pembacaan biasa.
 */
class SampahService
{
    /**
     * Keterangan tempat sampah: kapan dihapus dan berapa hari lagi dibuang permanen oleh sampah:bersihkan
     * (config rental.hari_sampah; 0 bila sudah lewat dan menunggu pembersihan berikutnya).
     *
     * sisa_hari null bila $bisaDibuang false (unit berriwayat tidak pernah dibuang permanen).
     *
     * @return array{dihapus_pada: string, sisa_hari: int|null}
     */
    public static function infoSampah(CarbonInterface $dihapus, bool $bisaDibuang = true): array
    {
        $batas = $dihapus->copy()->addDays(self::hariSampah());

        return [
            'dihapus_pada' => $dihapus->toIso8601String(),
            'sisa_hari' => $bisaDibuang ? max(0, (int) ceil(($batas->getTimestamp() - now()->getTimestamp()) / 86400)) : null,
        ];
    }

    public static function hariSampah(): int
    {
        return max(1, (int) config('rental.hari_sampah', 30));
    }

    /** GET /admin/sampah: tipe dan unit yang di-soft delete, terbaru dulu. */
    public function daftarSampah(): array
    {
        $tipe = TipeKendaraan::onlyTrashed()->withCount('foto')->orderByDesc('deleted_at')->orderByDesc('id_tipe')->get();
        $unitDiSampah = Kendaraan::onlyTrashed()->selectRaw('id_tipe, count(*) as jumlah')->groupBy('id_tipe')->pluck('jumlah', 'id_tipe');
        $unit = Kendaraan::onlyTrashed()->with(['tipe' => fn ($q) => $q->withTrashed()])->orderByDesc('deleted_at')->orderByDesc('id_kendaraan')->get();

        $ids = $unit->pluck('id_kendaraan');
        $riwayat = array_fill_keys(array_merge(
            Pemesanan::whereIn('id_kendaraan', $ids)->pluck('id_kendaraan')->all(),
            DB::table('serah_terima')->whereIn('id_kendaraan', $ids)->pluck('id_kendaraan')->all(),
            Perawatan::whereIn('id_kendaraan', $ids)->pluck('id_kendaraan')->all(),
        ), true);

        return [
            'hari_sampah' => self::hariSampah(),
            'tipe' => $tipe->map(fn ($t) => [
                'id' => $t->id_tipe, 'nama' => $t->nama, 'merk' => $t->merk, 'kursi' => $t->kursi, 'bahan_bakar' => $t->bahan_bakar,
                'jumlah_foto' => $t->foto_count, 'jumlah_unit_di_sampah' => (int) ($unitDiSampah[$t->id_tipe] ?? 0),
            ] + self::infoSampah($t->deleted_at))->values()->all(),
            'unit' => $unit->map(fn ($u) => [
                'id' => $u->id_kendaraan, 'plat_nomor' => $u->plat_nomor, 'tahun' => $u->tahun, 'warna' => $u->warna, 'transmisi' => $u->transmisi,
                'tipe' => $u->tipe ? ['id' => $u->tipe->id_tipe, 'nama' => $u->tipe->nama, 'terhapus' => $u->tipe->trashed()] : null,
                'punya_riwayat' => $riwayat[$u->id_kendaraan] ?? false,
            ] + self::infoSampah($u->deleted_at, ! ($riwayat[$u->id_kendaraan] ?? false)))->values()->all(),
        ];
    }

    public function pulihkanTipe(Akun $admin, int $id): TipeKendaraan
    {
        return DB::transaction(function () use ($admin, $id) {
            $tipe = $this->tipeDiSampah($id);
            $tipe->restore();
            LogAktivitas::catat("Tipe dipulihkan dari tempat sampah: {$tipe->nama}", $admin, 'tipe_kendaraan', $id);

            return $tipe->load('foto')->loadCount('kendaraan');
        });
    }

    /** Unit hanya bisa dipulihkan bila tipenya aktif: bila tipenya di tempat sampah, pulihkan tipe dulu (409 UNIT_TIPE_DI_SAMPAH). */
    public function pulihkanUnit(Akun $admin, int $id): Kendaraan
    {
        return DB::transaction(function () use ($admin, $id) {
            $unit = $this->unitDiSampah($id);
            $tipe = TipeKendaraan::withTrashed()->lockForUpdate()->find($unit->id_tipe);

            if (! $tipe || $tipe->trashed()) {
                throw new ApiException(
                    'Tipe unit ini ada di tempat sampah. Pulihkan tipe '.($tipe?->nama ?? '').' dulu, lalu pulihkan unit.',
                    'UNIT_TIPE_DI_SAMPAH', 409,
                    ['detail' => ['jenis' => 'tipe', 'id' => $unit->id_tipe, 'nama' => $tipe?->nama, 'merk' => $tipe?->merk]],
                );
            }

            $unit->restore();
            LogAktivitas::catat("Unit dipulihkan dari tempat sampah: {$unit->plat_nomor}", $admin, 'kendaraan', $id);

            return $unit->load('detail', 'tipe');
        });
    }

    /**
     * DELETE /admin/sampah/tipe/{id}: hapus permanen satu tipe di tempat sampah (konfirmasi=true wajib). Unit milik tipe itu yang
     * juga ada di tempat sampah ikut dibuang permanen (tidak bisa dipulihkan tanpa tipenya); foto dan berkasnya ikut terhapus.
     *
     * @return array{id: int, unit_ikut_dihapus: int, foto_dihapus: int}
     */
    public function hapusPermanenTipe(Akun $admin, int $id, bool $konfirmasi): array
    {
        [$hasil, $paths] = DB::transaction(function () use ($admin, $id, $konfirmasi) {
            $this->kunciUnitSampahMilikTipe($id);
            $tipe = $this->tipeDiSampah($id);

            if (! $konfirmasi) {
                $akan = Kendaraan::onlyTrashed()->where('id_tipe', $id)->count();
                throw new ApiException(
                    "Hapus permanen tipe {$tipe->nama} tidak bisa dibatalkan. Kirim ulang dengan konfirmasi=true.", 'KONFIRMASI_DIPERLUKAN', 409,
                    ['detail' => ['jenis' => 'tipe', 'id' => $id, 'nama' => $tipe->nama, 'merk' => $tipe->merk, 'unit_ikut_dihapus' => $akan, 'foto_dihapus' => TipeFoto::where('id_tipe', $id)->count()]],
                );
            }

            return $this->buangTipe($tipe, $admin, 'dihapus permanen');
        });

        $this->hapusBerkas($paths, "tipe/{$id}");

        return $hasil;
    }

    /** DELETE /admin/sampah/unit/{id}: hapus permanen satu unit di tempat sampah (konfirmasi=true wajib), beserta detailnya. */
    public function hapusPermanenUnit(Akun $admin, int $id, bool $konfirmasi): array
    {
        return DB::transaction(function () use ($admin, $id, $konfirmasi) {
            $unit = $this->unitDiSampah($id);

            if (self::punyaRiwayat($id)) {
                throw $this->unitBerriwayat($unit);
            }

            if (! $konfirmasi) {
                throw new ApiException(
                    "Hapus permanen unit {$unit->plat_nomor} tidak bisa dibatalkan. Kirim ulang dengan konfirmasi=true.", 'KONFIRMASI_DIPERLUKAN', 409,
                    ['detail' => ['jenis' => 'unit', 'id' => $id, 'plat_nomor' => $unit->plat_nomor]],
                );
            }

            $this->buangUnit($unit, $admin, 'dihapus permanen');

            return ['id' => $id];
        });
    }

    /**
     * Dipakai sampah:bersihkan. Membuang permanen unit yang deleted_at-nya lebih lama dari $batas; false bila sudah dipulihkan,
     * dihapus lebih baru dari batas, atau masih dirujuk (data tidak disentuh). Hanya menyentuh baris ber-deleted_at.
     */
    public function buangUnitKedaluwarsa(int $id, CarbonInterface $batas): bool
    {
        return DB::transaction(function () use ($id, $batas) {
            $unit = Kendaraan::onlyTrashed()->lockForUpdate()->find($id);
            if (! $unit || $unit->deleted_at->gte($batas) || self::punyaRiwayat($id)) {
                return false; // unit berriwayat tidak pernah dibuang otomatis
            }

            $this->buangUnit($unit, null, 'dibuang otomatis dari tempat sampah');

            return true;
        });
    }

    /**
     * Seperti buangUnitKedaluwarsa untuk tipe. Tipe yang masih punya unit (aktif, atau di tempat sampah tetapi belum kedaluwarsa)
     * dilewati: unit yang lebih baru tidak ikut dibuang. Nilai: null bila dilewati, atau ringkasan bila dibuang.
     *
     * @return array{id: int, unit_ikut_dihapus: int, foto_dihapus: int}|null
     */
    public function buangTipeKedaluwarsa(int $id, CarbonInterface $batas): ?array
    {
        $hasil = DB::transaction(function () use ($id, $batas) {
            $this->kunciUnitSampahMilikTipe($id);
            $tipe = TipeKendaraan::onlyTrashed()->lockForUpdate()->find($id);
            if (! $tipe || $tipe->deleted_at->gte($batas) || Kendaraan::withTrashed()->where('id_tipe', $id)->exists()) {
                return null;
            }

            return $this->buangTipe($tipe, null, 'dibuang otomatis dari tempat sampah');
        });

        if ($hasil === null) {
            return null;
        }

        [$ringkas, $paths] = $hasil;
        $this->hapusBerkas($paths, "tipe/{$id}");

        return $ringkas;
    }

    private function tipeDiSampah(int $id): TipeKendaraan
    {
        $tipe = TipeKendaraan::withTrashed()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Tipe');

        return $tipe->trashed() ? $tipe : throw new ApiException('Tipe ini masih aktif, tidak ada di tempat sampah', 'TIDAK_DI_SAMPAH', 409);
    }

    private function unitDiSampah(int $id): Kendaraan
    {
        $unit = Kendaraan::withTrashed()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Unit');

        return $unit->trashed() ? $unit : throw new ApiException('Unit ini masih aktif, tidak ada di tempat sampah', 'TIDAK_DI_SAMPAH', 409);
    }

    /** Urutan kunci sama dengan pemulihan unit (unit lalu tipe), supaya dua proses bersamaan tidak saling menunggu. */
    private function kunciUnitSampahMilikTipe(int $idTipe): void
    {
        Kendaraan::onlyTrashed()->where('id_tipe', $idTipe)->lockForUpdate()->pluck('id_kendaraan');
    }

    /** Riwayat = pernah ada pesanan, serah terima, atau servis. Unit berriwayat tidak pernah dibuang permanen. */
    public static function punyaRiwayat(int $idUnit): bool
    {
        return Pemesanan::where('id_kendaraan', $idUnit)->exists()
            || DB::table('serah_terima')->where('id_kendaraan', $idUnit)->exists()
            || Perawatan::where('id_kendaraan', $idUnit)->exists();
    }

    private function unitBerriwayat(Kendaraan $unit): ApiException
    {
        return new ApiException(
            "Unit {$unit->plat_nomor} punya riwayat pesanan atau servis sehingga tidak bisa dibuang permanen. Pulihkan saja unit ini.",
            'UNIT_PUNYA_RIWAYAT', 409,
            ['detail' => ['jenis' => 'unit', 'id' => $unit->id_kendaraan, 'plat_nomor' => $unit->plat_nomor, 'punya_riwayat' => true]],
        );
    }

    /** Buang satu unit di tempat sampah: baris detail lalu barisnya. Tidak ada berkas pada unit. */
    private function buangUnit(Kendaraan $unit, ?Akun $pelaku, string $ket): void
    {
        if (self::punyaRiwayat($unit->id_kendaraan)) {
            throw $this->unitBerriwayat($unit);
        }

        DetailKendaraan::where('id_kendaraan', $unit->id_kendaraan)->delete();
        $unit->forceDelete();
        LogAktivitas::catat("Unit {$ket}: {$unit->plat_nomor}", $pelaku, 'kendaraan', $unit->id_kendaraan);
    }

    /** @return array{0: array{id: int, unit_ikut_dihapus: int, foto_dihapus: int}, 1: list<string>} ringkasan dan berkas foto yang harus dihapus */
    private function buangTipe(TipeKendaraan $tipe, ?Akun $pelaku, string $ket): array
    {
        $id = $tipe->id_tipe;
        if (Kendaraan::where('id_tipe', $id)->exists() || Pemesanan::where('id_tipe', $id)->exists()) {
            throw new ApiException('Tipe masih dipakai unit aktif atau pesanan, tidak bisa dibuang permanen', 'TIPE_MASIH_DIPAKAI');
        }

        $unit = Kendaraan::onlyTrashed()->where('id_tipe', $id)->get();
        foreach ($unit as $u) {
            $this->buangUnit($u, $pelaku, $ket);
        }

        $paths = TipeFoto::where('id_tipe', $id)->pluck('path_foto')->all();
        TipeFoto::where('id_tipe', $id)->delete();
        $tipe->forceDelete();
        LogAktivitas::catat("Tipe {$ket}: {$tipe->nama}", $pelaku, 'tipe_kendaraan', $id);

        return [['id' => $id, 'unit_ikut_dihapus' => $unit->count(), 'foto_dihapus' => count($paths)], $paths];
    }

    /** Hapus berkas foto dari disk setelah transaksi berhasil. Berkas yang hilang atau gagal dihapus tidak menghentikan yang lain. */
    private function hapusBerkas(array $paths, string $folder): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable $e) {
                report($e);
            }
        }
        try {
            Storage::disk('public')->deleteDirectory($folder); // folder milik tipe ini saja
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function tidakDitemukan(string $apa): ApiException
    {
        return new ApiException("{$apa} tidak ditemukan", 'TIDAK_DITEMUKAN', 404);
    }
}
