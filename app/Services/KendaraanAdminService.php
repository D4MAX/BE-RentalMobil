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
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Kelola tipe dan unit (Spek API bagian 8). Setiap aksi menulis log_aktivitas atas nama admin.
 *
 * Urutan kunci: unit lalu tipe (pemesanan baru hanya mengunci tipe, serah terima mengunci pesanan lalu unit), dan
 * semua kunci diambil sebelum pembacaan biasa (snapshot REPEATABLE READ).
 */
class KendaraanAdminService
{
    /** Batas total foto per tipe (Spek API bagian 8). */
    public const MAKS_FOTO_TIPE = 3;

    public function __construct(private KetersediaanService $ketersediaan) {}

    // ------------------------------------------------------------------ tipe

    public function bentukTipe(TipeKendaraan $t): array
    {
        $foto = $t->foto->map(fn ($f) => ['id' => $f->id_foto, 'url' => Storage::disk('public')->url($f->path_foto)])->values()->all();

        return [
            'id' => $t->id_tipe, 'nama' => $t->nama, 'merk' => $t->merk, 'kursi' => $t->kursi, 'bahan_bakar' => $t->bahan_bakar,
            'harga_sopir_per_hari' => $t->harga_sopir_per_hari,
            'harga_lepas_kunci_manual' => $t->harga_lepas_kunci_manual,
            'harga_lepas_kunci_matic' => $t->harga_lepas_kunci_matic,
            'foto' => $foto, 'jumlah_foto' => count($foto),
            'jumlah_unit' => $t->kendaraan_count ?? $t->kendaraan()->count(),
        ];
    }

    /** @param  list<UploadedFile>  $foto */
    public function buatTipe(Akun $admin, array $d, array $foto = []): TipeKendaraan
    {
        $tersimpan = [];

        try {
            $tipe = DB::transaction(function () use ($admin, $d, $foto, &$tersimpan) {
                $this->periksaTipeUnik($d['nama'], $d['merk'], null);
                $tipe = TipeKendaraan::create($this->kolomTipe($d));
                $this->simpanFoto($tipe, $foto, $tersimpan);
                LogAktivitas::catat("Tipe ditambah: {$tipe->nama}", $admin, 'tipe_kendaraan', $tipe->id_tipe);

                return $tipe;
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($tersimpan);

            throw $this->ubahIndeksTipeJadi422($e, $d);
        }

        return $tipe->load('foto');
    }

    /** Nama dan merk unik tanpa peka huruf besar kecil dan tanpa spasi di tepi; tipe itu sendiri diabaikan saat ubah. */
    private function periksaTipeUnik(string $nama, string $merk, ?int $idSendiri): void
    {
        $ada = TipeKendaraan::query()
            ->whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower(trim($nama))])
            ->whereRaw('LOWER(TRIM(merk)) = ?', [mb_strtolower(trim($merk))])
            ->when($idSendiri, fn ($q) => $q->where('id_tipe', '!=', $idSendiri))
            ->exists();

        if ($ada) {
            throw $this->tipeSudahAda($nama, $merk);
        }

        // Bentrok dengan tipe di tempat sampah: indeks unik database tetap menahan barisnya, jadi pilihan ada pada admin
        $sampah = TipeKendaraan::onlyTrashed()
            ->whereRaw('LOWER(TRIM(nama)) = ?', [mb_strtolower(trim($nama))])
            ->whereRaw('LOWER(TRIM(merk)) = ?', [mb_strtolower(trim($merk))])
            ->orderBy('id_tipe')->first();

        if ($sampah) {
            $pesan = "Tipe {$sampah->nama} dengan merk {$sampah->merk} ada di tempat sampah. Pulihkan tipe itu, atau hapus permanen dulu lalu buat ulang.";

            throw new ApiException($pesan, 'TIPE_ADA_DI_SAMPAH', 422, [
                'errors' => ['nama' => [$pesan]],
                'detail' => ['jenis' => 'tipe', 'id' => $sampah->id_tipe, 'nama' => $sampah->nama, 'merk' => $sampah->merk, 'kolom_bentrok' => ['nama', 'merk']]
                    + SampahService::infoSampah($sampah->deleted_at),
            ]);
        }
    }

    private function tipeSudahAda(string $nama, string $merk): ApiException
    {
        $pesan = "Tipe {$nama} dengan merk {$merk} sudah ada.";

        return new ApiException($pesan, 'TIPE_SUDAH_ADA', 422, ['errors' => ['nama' => [$pesan]]]);
    }

    /** Jaring pengaman balapan dua permintaan bersamaan: indeks unik database menolak, jawabannya tetap TIPE_SUDAH_ADA. */
    private function ubahIndeksTipeJadi422(Throwable $e, array $d): Throwable
    {
        if ($e instanceof QueryException && $e->getCode() === '23000' && str_contains($e->getMessage(), 'tipe_kendaraan_nama_merk_unique')) {
            return $this->tipeSudahAda($d['nama'], $d['merk']);
        }

        return $e;
    }

    /**
     * Foto yang dikirim DITAMBAHKAN ke foto yang sudah ada; total per tipe maksimal 3 (lebih dari itu 422 dan tidak ada
     * yang berubah). Tanpa foto, foto lama dibiarkan. Satu foto dihapus lewat hapusFoto(). Tipe boleh punya 0 foto.
     * Tarif baru hanya berlaku untuk pesanan baru: pesanan lama menyimpan total_biaya sendiri.
     *
     * @param  list<UploadedFile>  $foto
     */
    public function ubahTipe(Akun $admin, int $id, array $d, array $foto = []): TipeKendaraan
    {
        $tersimpan = [];

        try {
            $tipe = DB::transaction(function () use ($admin, $id, $d, $foto, &$tersimpan) {
                $tipe = TipeKendaraan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Tipe');
                $this->periksaTipeUnik($d['nama'], $d['merk'], $id);
                $tipe->update($this->kolomTipe($d));

                if ($foto) {
                    // Dihitung di bawah kunci tipe, jadi dua unggahan bersamaan tidak bisa sama-sama lolos batas
                    $ada = TipeFoto::where('id_tipe', $id)->count();
                    $sisa = self::MAKS_FOTO_TIPE - $ada;
                    if (count($foto) > $sisa) {
                        throw ValidationException::withMessages(['foto' => [$sisa <= 0
                            ? 'Foto sudah '.$ada.' (maksimal '.self::MAKS_FOTO_TIPE.'). Hapus foto lama dulu sebelum menambah.'
                            : "Foto tinggal bisa ditambah {$sisa} lagi (sudah ada {$ada}, maksimal ".self::MAKS_FOTO_TIPE.').',
                        ]]);
                    }
                    $this->simpanFoto($tipe, $foto, $tersimpan, (int) TipeFoto::where('id_tipe', $id)->max('urutan'));
                }
                LogAktivitas::catat("Tipe diubah: {$tipe->nama}", $admin, 'tipe_kendaraan', $id);

                return $tipe;
            });
        } catch (Throwable $e) {
            Storage::disk('public')->delete($tersimpan);

            throw $this->ubahIndeksTipeJadi422($e, $d);
        }

        return $tipe->load('foto');
    }

    /** Hapus satu foto milik tipe ini. Berkasnya dihapus dari disk hanya setelah transaksi berhasil. */
    public function hapusFoto(Akun $admin, int $id, int $idFoto): TipeKendaraan
    {
        [$tipe, $path] = DB::transaction(function () use ($admin, $id, $idFoto) {
            $tipe = TipeKendaraan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Tipe');
            $foto = TipeFoto::where('id_tipe', $id)->where('id_foto', $idFoto)->first()
                ?? throw new ApiException('Foto tidak ditemukan pada tipe ini', 'TIDAK_DITEMUKAN', 404);
            $foto->delete();
            LogAktivitas::catat("Foto tipe dihapus: {$tipe->nama}", $admin, 'tipe_kendaraan', $id);

            return [$tipe, $foto->path_foto];
        });

        Storage::disk('public')->delete($path);

        return $tipe->load('foto');
    }

    /**
     * Soft delete: hanya mengisi deleted_at. Foto (baris dan berkas), tarif, dan nomor unik tetap ada supaya bisa dipulihkan.
     * Aturan penolakan sama seperti dulu: tipe yang masih punya unit aktif atau pernah dipesan tidak boleh dihapus.
     */
    public function hapusTipe(Akun $admin, int $id): void
    {
        DB::transaction(function () use ($admin, $id) {
            $tipe = TipeKendaraan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Tipe');

            if (Kendaraan::where('id_tipe', $id)->exists() || Pemesanan::where('id_tipe', $id)->exists()) {
                throw new ApiException('Hapus unit tipe ini lebih dulu', 'TIPE_MASIH_DIPAKAI');
            }

            $tipe->delete();
            LogAktivitas::catat("Tipe dihapus ke tempat sampah: {$tipe->nama}", $admin, 'tipe_kendaraan', $id);
        });
    }

    private function kolomTipe(array $d): array
    {
        return array_intersect_key($d, array_flip([
            'nama', 'merk', 'kursi', 'bahan_bakar', 'harga_sopir_per_hari', 'harga_lepas_kunci_manual', 'harga_lepas_kunci_matic',
        ]));
    }

    /** @param  list<UploadedFile>  $foto */
    private function simpanFoto(TipeKendaraan $tipe, array $foto, array &$tersimpan, int $urutanAwal = 0): void
    {
        foreach (array_values($foto) as $i => $berkas) {
            $tersimpan[] = $path = $berkas->store("tipe/{$tipe->id_tipe}", 'public');
            TipeFoto::create(['id_tipe' => $tipe->id_tipe, 'path_foto' => $path, 'urutan' => $urutanAwal + $i + 1]);
        }
    }

    // ------------------------------------------------------------------ unit

    /** @param  bool|null  $disewa  sudah diketahui pemanggil (daftar): menghindari satu query per unit; null = dicek di sini */
    public function bentukUnit(Kendaraan $u, ?bool $disewa = null): array
    {
        $d = $u->detail;
        $disewa ??= Pemesanan::where('id_kendaraan', $u->id_kendaraan)->where('status', 'berjalan')->exists();

        return [
            'id' => $u->id_kendaraan, 'id_tipe' => $u->id_tipe, 'tipe' => $u->tipe ? ['id' => $u->tipe->id_tipe, 'nama' => $u->tipe->nama] : null,
            'plat_nomor' => $u->plat_nomor, 'tahun' => $u->tahun, 'warna' => $u->warna, 'transmisi' => $u->transmisi,
            'km_terakhir' => $u->km_terakhir, 'status' => $u->status,
            // Disewa tidak disimpan di kolom status: dihitung dari pesanan berjalan yang memakai unit ini
            'sedang_disewa' => $disewa,
            // Label untuk tampilan: kondisi unit, atau "disewa" bila kondisinya tersedia tetapi sedang dipakai
            'status_tampil' => $u->status === 'tersedia' && $disewa ? 'disewa' : $u->status,
            'detail' => [
                'nama_pemilik' => $d?->nama_pemilik, 'status_kepemilikan' => $d?->status_kepemilikan ?? 'milik_perusahaan',
                'no_rangka' => $d?->no_rangka, 'no_mesin' => $d?->no_mesin, 'no_bpkb' => $d?->no_bpkb,
                'masa_berlaku_stnk' => $d?->masa_berlaku_stnk?->toDateString() ?? null, 'catatan' => $d?->catatan,
            ],
        ];
    }

    /**
     * Pengecekan di controller memberi 422 yang ramah; ini jaring pengaman untuk balapan dua permintaan bersamaan, saat
     * indeks unik database yang menolak. Galatnya diubah menjadi 422 pada kolom yang sama, bukan 500.
     */
    private function ubahBentrokJadi422(callable $aksi): mixed
    {
        try {
            return $aksi();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'Duplicate entry')) {
                foreach (['plat_nomor' => ['plat_nomor', 'Plat nomor'], 'no_rangka' => ['detail.no_rangka', 'No rangka'],
                    'no_mesin' => ['detail.no_mesin', 'No mesin'], 'no_bpkb' => ['detail.no_bpkb', 'No BPKB']] as $kolom => [$kunci, $nama]) {
                    if (str_contains($e->getMessage(), $kolom)) {
                        throw ValidationException::withMessages([$kunci => ["{$nama} sudah dipakai unit lain."]]);
                    }
                }
            }

            throw $e;
        }
    }

    public function buatUnit(Akun $admin, array $d): Kendaraan
    {
        return $this->ubahBentrokJadi422(fn () => DB::transaction(function () use ($admin, $d) {
            $this->periksaBentrokSampahUnit($d, null);
            $unit = Kendaraan::create($this->kolomUnit($d) + ['status' => 'tersedia']);
            DetailKendaraan::create(['id_kendaraan' => $unit->id_kendaraan] + $this->kolomDetail($d));
            LogAktivitas::catat("Unit ditambah: {$unit->plat_nomor}", $admin, 'kendaraan', $unit->id_kendaraan);

            return $unit->refresh()->load('detail', 'tipe'); // refresh: nilai bawaan database (km 0) ikut terbaca
        }));
    }

    public function ubahUnit(Akun $admin, int $id, array $d): Kendaraan
    {
        return $this->ubahBentrokJadi422(fn () => DB::transaction(function () use ($admin, $id, $d) {
            $unit = Kendaraan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Unit');
            TipeKendaraan::query()->lockForUpdate()->find($unit->id_tipe);
            $this->periksaBentrokSampahUnit($d, $id);

            // Memindahkan unit ke tipe lain mengubah kapasitas dua tipe dan riwayat pesanan: hanya unit tanpa pesanan
            if ((int) $d['id_tipe'] !== (int) $unit->id_tipe && Pemesanan::where('id_kendaraan', $id)->exists()) {
                throw new ApiException('Unit sudah pernah dipakai pesanan, tipenya tidak bisa diubah', 'UNIT_PUNYA_PEMESANAN');
            }

            $unit->update($this->kolomUnit($d));
            DetailKendaraan::updateOrCreate(['id_kendaraan' => $id], $this->kolomDetail($d));
            LogAktivitas::catat("Unit diubah: {$unit->plat_nomor}", $admin, 'kendaraan', $id);

            return $unit->load('detail', 'tipe');
        }));
    }

    /**
     * Hapus unit (ke tempat sampah). Unit berriwayat (pesanan, serah terima, servis) BOLEH dihapus; ditolak hanya bila sedang disewa
     * (UNIT_SEDANG_DISEWA) atau dalam servis (UNIT_DALAM_SERVIS). Menurunkan kapasitas seperti menonaktifkan (PESANAN_TERDAMPAK).
     */
    public function hapusUnit(Akun $admin, int $id, bool $konfirmasi): void
    {
        DB::transaction(function () use ($admin, $id, $konfirmasi) {
            $unit = Kendaraan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Unit');
            TipeKendaraan::query()->lockForUpdate()->find($unit->id_tipe);

            if (Pemesanan::where('id_kendaraan', $id)->where('status', 'berjalan')->exists()) {
                throw new ApiException('Unit sedang disewa, tidak bisa dihapus', 'UNIT_SEDANG_DISEWA');
            }
            if ($unit->status === 'dalam_perawatan' || Perawatan::where('id_kendaraan', $id)->where('status', 'berlangsung')->exists()) {
                throw new ApiException('Unit sedang dalam servis, selesaikan atau batalkan servisnya dulu', 'UNIT_DALAM_SERVIS');
            }

            if ($unit->status === 'tersedia') {
                $this->periksaTerdampak($unit, $konfirmasi);
            }

            $unit->delete(); // soft delete: detail_kendaraan tetap ada (nomor unik tetap tertahan) sampai dibuang permanen
            LogAktivitas::catat("Unit dihapus ke tempat sampah: {$unit->plat_nomor}", $admin, 'kendaraan', $id);
        });
    }

    /**
     * PATCH /admin/unit/{id}/status. Ditolak bila unit sedang disewa (UNIT_SEDANG_DISEWA) atau dalam servis
     * (UNIT_DALAM_SERVIS). Menandai tidak tersedia yang membuat pesanan dikonfirmasi kekurangan unit memberi
     * PESANAN_TERDAMPAK (peringatan, bukan larangan): kirim ulang dengan konfirmasi=true untuk tetap melanjutkan.
     */
    public function ubahStatusUnit(Akun $admin, int $id, string $status, bool $konfirmasi): array
    {
        return DB::transaction(function () use ($admin, $id, $status, $konfirmasi) {
            $unit = Kendaraan::query()->lockForUpdate()->find($id) ?? throw $this->tidakDitemukan('Unit');
            TipeKendaraan::query()->lockForUpdate()->find($unit->id_tipe);

            if (Pemesanan::where('id_kendaraan', $id)->where('status', 'berjalan')->exists()) {
                throw new ApiException('Unit sedang disewa, status tidak bisa diubah', 'UNIT_SEDANG_DISEWA');
            }
            if ($unit->status === 'dalam_perawatan') {
                throw new ApiException('Unit sedang dalam servis, selesaikan atau batalkan servisnya dulu', 'UNIT_DALAM_SERVIS');
            }

            if ($unit->status !== $status) {
                if ($status === 'tidak_tersedia') {
                    $this->periksaTerdampak($unit, $konfirmasi);
                }
                $unit->update(['status' => $status]);
                LogAktivitas::catat("Unit {$unit->plat_nomor} ditandai {$status}".($konfirmasi ? ' (dengan konfirmasi pesanan terdampak)' : ''), $admin, 'kendaraan', $id);
            }

            return ['status' => $status];
        });
    }

    /** Kapasitas tipe turun satu (unit ini keluar): pesanan yang kehilangan unit memberi 409 kecuali sudah dikonfirmasi. */
    public function periksaTerdampak(Kendaraan $unit, bool $konfirmasi): void
    {
        if ($konfirmasi) {
            return;
        }

        $kapasitasBaru = Kendaraan::where('id_tipe', $unit->id_tipe)->where('status', 'tersedia')->count() - 1;
        $terdampak = $this->ketersediaan->pesananTerdampak($unit->id_tipe, $kapasitasBaru);

        if ($terdampak->isNotEmpty()) {
            throw new ApiException(
                'Ada pesanan dikonfirmasi yang membutuhkan unit tipe ini', 'PESANAN_TERDAMPAK', 409,
                ['pesanan_terdampak' => KetersediaanService::ringkasTerdampak($terdampak)]
            );
        }
    }

    /**
     * Plat, no rangka, no mesin, atau no BPKB yang sama dengan unit di tempat sampah: 422 UNIT_ADA_DI_SAMPAH beserta data unit
     * lama (id, plat, kolom yang bentrok, tanggal dihapus, sisa hari) supaya tampilan bisa menawarkan "pulihkan" atau "hapus
     * permanen lalu buat baru". Bentrok dengan unit aktif sudah dijawab lebih dulu oleh validasi (kode lama).
     */
    private function periksaBentrokSampahUnit(array $d, ?int $idSendiri): void
    {
        $konflik = []; // id_kendaraan => kolom yang bentrok

        $plat = str_replace(' ', '', mb_strtoupper((string) ($d['plat_nomor'] ?? '')));
        $unitPlat = Kendaraan::onlyTrashed()->whereRaw("REPLACE(UPPER(plat_nomor), ' ', '') = ?", [$plat])
            ->when($idSendiri, fn ($q) => $q->where('id_kendaraan', '!=', $idSendiri))->pluck('id_kendaraan');
        foreach ($unitPlat as $id) {
            $konflik[$id][] = 'plat_nomor';
        }

        foreach (['no_rangka', 'no_mesin', 'no_bpkb'] as $kolom) {
            $nilai = mb_strtolower(trim((string) ($d['detail'][$kolom] ?? '')));
            if ($nilai === '') {
                continue;
            }
            $ids = DetailKendaraan::query()->whereRaw("LOWER(TRIM({$kolom})) = ?", [$nilai])
                ->whereHas('kendaraan', fn ($q) => $q->onlyTrashed())
                ->when($idSendiri, fn ($q) => $q->where('id_kendaraan', '!=', $idSendiri))->pluck('id_kendaraan');
            foreach ($ids as $id) {
                $konflik[$id][] = $kolom;
            }
        }

        if (! $konflik) {
            return;
        }

        ksort($konflik);
        $id = array_key_first($konflik);
        $lama = Kendaraan::onlyTrashed()->find($id);
        $kolom = $konflik[$id];
        $punya = SampahService::punyaRiwayat($id);
        $label = ['plat_nomor' => 'Plat nomor', 'no_rangka' => 'No rangka', 'no_mesin' => 'No mesin', 'no_bpkb' => 'No BPKB'];
        $sebut = implode(', ', array_map(fn ($k) => $label[$k], $kolom));
        $pesan = "{$sebut} sama dengan unit {$lama->plat_nomor} yang ada di tempat sampah. Pulihkan unit itu, atau hapus permanen dulu lalu buat ulang.";
        $kunci = $kolom[0] === 'plat_nomor' ? 'plat_nomor' : 'detail.'.$kolom[0];

        throw new ApiException($pesan, 'UNIT_ADA_DI_SAMPAH', 422, [
            'errors' => [$kunci => [$pesan]],
            'detail' => ['jenis' => 'unit', 'id' => $lama->id_kendaraan, 'plat_nomor' => $lama->plat_nomor, 'kolom_bentrok' => $kolom, 'punya_riwayat' => $punya]
                + SampahService::infoSampah($lama->deleted_at, ! $punya),
        ]);
    }

    private function kolomDetail(array $d): array
    {
        $kolom = array_intersect_key($d['detail'] ?? [], array_flip(['nama_pemilik', 'status_kepemilikan', 'no_rangka', 'no_mesin', 'no_bpkb', 'masa_berlaku_stnk', 'catatan']));
        if (($kolom['status_kepemilikan'] ?? null) === null) {
            unset($kolom['status_kepemilikan']); // kolom tidak boleh NULL: kosong = bawaan saat tambah, nilai lama saat ubah (form mengirim teks kosong)
        }

        return $kolom;
    }

    private function kolomUnit(array $d): array
    {
        $kolom = array_intersect_key($d, array_flip(['id_tipe', 'plat_nomor', 'tahun', 'warna', 'transmisi', 'km_terakhir']));
        if (($kolom['km_terakhir'] ?? null) === null) {
            unset($kolom['km_terakhir']); // kosong: pakai bawaan (0) saat tambah, biarkan nilai lama saat ubah
        }

        return $kolom;
    }

    private function tidakDitemukan(string $apa): ApiException
    {
        return new ApiException("{$apa} tidak ditemukan", 'TIDAK_DITEMUKAN', 404);
    }
}
