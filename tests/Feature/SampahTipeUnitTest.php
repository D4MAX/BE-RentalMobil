<?php

namespace Tests\Feature;

use App\Models\Kendaraan;
use App\Models\Pemesanan;
use App\Models\Perawatan;
use App\Models\TipeFoto;
use App\Models\TipeKendaraan;
use App\Services\KetersediaanService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/**
 * Soft delete tipe dan unit, tempat sampah (/admin/sampah), pemulihan, bentrok keunikan dengan tempat sampah
 * (TIPE_ADA_DI_SAMPAH, UNIT_ADA_DI_SAMPAH), hapus permanen satu data, dan pembersihan terjadwal sampah:bersihkan.
 */
class SampahTipeUnitTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const TIPE = '/api/v1/admin/tipe';

    private const UNIT = '/api/v1/admin/unit';

    private const SAMPAH = '/api/v1/admin/sampah';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        [, $this->token] = $this->buatAdmin();
    }

    private function panggil(string $metode, string $url, array $isi = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($metode, $url, $isi);
    }

    private function form(string $url, array $isi)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->post($url, $isi, ['Accept' => 'application/json']);
    }

    private function bodyTipe(array $ubah = []): array
    {
        return $ubah + ['nama' => 'Brio', 'merk' => 'Honda', 'kursi' => 5, 'bahan_bakar' => 'bensin',
            'harga_sopir_per_hari' => 650000, 'harga_lepas_kunci_manual' => 300000, 'harga_lepas_kunci_matic' => 350000];
    }

    private function bodyUnit(int $idTipe, array $ubah = [], array $detail = []): array
    {
        return $ubah + ['id_tipe' => $idTipe, 'plat_nomor' => 'N 1234 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'km_terakhir' => 100,
            'detail' => $detail + ['nama_pemilik' => 'PT Contoh', 'status_kepemilikan' => 'milik_perusahaan', 'no_rangka' => 'RANGKA-1', 'no_mesin' => 'MESIN-1',
                'no_bpkb' => 'BPKB-1', 'masa_berlaku_stnk' => '2027-03-15', 'catatan' => '']];
    }

    /** Tipe Brio dengan satu foto lewat API, lalu dibuang ke tempat sampah. @return array{0:int,1:string} id dan path foto */
    private function tipeDiSampah(string $nama = 'Brio'): array
    {
        $id = $this->form(self::TIPE, $this->bodyTipe(['nama' => $nama]) + ['foto' => [UploadedFile::fake()->image('a.jpg')]])->assertCreated()->json('data.id');
        $path = TipeFoto::where('id_tipe', $id)->value('path_foto');
        $this->panggil('DELETE', self::TIPE."/{$id}")->assertNoContent();

        return [$id, $path];
    }

    /** Unit lewat API pada tipe aktif baru, lalu dibuang ke tempat sampah. @return array{0:int,1:int} id unit dan id tipe */
    private function unitDiSampah(array $ubah = [], array $detail = []): array
    {
        $tipe = TipeKendaraan::where('nama', 'Avanza')->first() ?? $this->buatTipe(0, 'Avanza');
        $id = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, $ubah, $detail))->assertCreated()->json('data.id');
        $this->panggil('DELETE', self::UNIT."/{$id}")->assertNoContent();

        return [$id, $tipe->id_tipe];
    }

    private function umurkan(string $model, int $id, int $hari): void
    {
        $model::withTrashed()->whereKey($id)->update(['deleted_at' => now()->subDays($hari)]);
    }

    // ================================================================ soft delete

    public function test_soft_delete_tipe_dan_unit_tidak_muncul_di_katalog_daftar_admin_dan_ketersediaan(): void
    {
        $tipe = $this->buatTipe(2, 'Avanza');
        $unit = $tipe->kendaraan()->orderBy('id_kendaraan')->get();
        $mulai = now()->addDays(3)->toDateString();
        $akhir = now()->addDays(4)->toDateString();
        $this->assertSame(2, (new KetersediaanService)->sisaUnit($mulai, $akhir)[$tipe->id_tipe]);

        $this->panggil('DELETE', self::UNIT."/{$unit[0]->id_kendaraan}")->assertNoContent();

        $this->assertSame(1, (new KetersediaanService)->sisaUnit($mulai, $akhir)[$tipe->id_tipe]);
        $this->panggil('GET', self::UNIT)->assertOk()->assertJsonCount(1, 'data');
        $this->panggil('GET', self::UNIT."/{$unit[0]->id_kendaraan}")->assertNotFound();
        $this->panggil('GET', self::TIPE."/{$tipe->id_tipe}")->assertOk()->assertJsonPath('data.jumlah_unit', 1);

        $this->panggil('DELETE', self::UNIT."/{$unit[1]->id_kendaraan}")->assertNoContent();
        $this->panggil('DELETE', self::TIPE."/{$tipe->id_tipe}")->assertNoContent();

        $this->getJson("/api/v1/katalog/tipe?tgl_mulai={$mulai}&durasi=2")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/katalog/tipe/{$tipe->id_tipe}")->assertNotFound();
        $this->panggil('GET', self::TIPE)->assertOk()->assertJsonCount(0, 'data');
        $this->panggil('GET', self::TIPE."/{$tipe->id_tipe}")->assertNotFound();
        $this->assertSame(0, Kendaraan::count());
        $this->assertSame(2, Kendaraan::onlyTrashed()->count());
    }

    public function test_unit_dan_tipe_di_sampah_tidak_bisa_dipakai_pesanan_atau_servis_dan_aturan_penolakan_tetap(): void
    {
        [$budi] = $this->buatPelanggan();
        $tipe = $this->buatTipe(2, 'Avanza');
        $unit = $tipe->kendaraan()->orderBy('id_kendaraan')->get();
        $this->panggil('DELETE', self::UNIT."/{$unit[0]->id_kendaraan}")->assertNoContent();

        // Unit di tempat sampah tidak bisa ditandai, diubah, atau diservis
        $this->panggil('PATCH', self::UNIT."/{$unit[0]->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertNotFound();
        $this->panggil('POST', '/api/v1/admin/servis', ['id_kendaraan' => $unit[0]->id_kendaraan, 'jenis_servis' => 'Servis', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString()])
            ->assertStatus(422);

        // Aturan lama tetap: tipe dengan unit aktif atau pesanan tidak boleh dihapus; unit berriwayat boleh, yang disewa tidak
        $this->panggil('DELETE', self::TIPE."/{$tipe->id_tipe}")->assertStatus(409)->assertJsonPath('code', 'TIPE_MASIH_DIPAKAI');
        $this->buatPesanan($budi, $tipe, ['status' => 'berjalan', 'id_kendaraan' => $unit[1]->id_kendaraan]);
        $this->panggil('DELETE', self::UNIT."/{$unit[1]->id_kendaraan}")->assertStatus(409)->assertJsonPath('code', 'UNIT_SEDANG_DISEWA');

        // Tipe di tempat sampah tidak bisa dipesan
        $kosong = $this->buatTipe(0, 'Xenia');
        $this->panggil('DELETE', self::TIPE."/{$kosong->id_tipe}")->assertNoContent();
        $this->app['auth']->forgetGuards();
        [, $tokenUser] = $this->buatPelanggan('user2@mail.test');
        $this->withToken($tokenUser)->postJson('/api/v1/pemesanan', ['id_tipe' => $kosong->id_tipe])->assertStatus(422);
    }

    public function test_foto_tipe_dan_berkasnya_tidak_dihapus_saat_soft_delete(): void
    {
        [$id, $path] = $this->tipeDiSampah();

        Storage::disk('public')->assertExists($path);
        $this->assertSame(1, TipeFoto::where('id_tipe', $id)->count());
    }

    // ================================================================ daftar sampah dan pemulihan

    public function test_daftar_sampah_memuat_tanggal_hapus_dan_sisa_hari(): void
    {
        [$idTipe] = $this->tipeDiSampah();
        [$idUnit] = $this->unitDiSampah();

        $r = $this->panggil('GET', self::SAMPAH)->assertOk()
            ->assertJsonPath('data.hari_sampah', 30)
            ->assertJsonPath('data.tipe.0.id', $idTipe)->assertJsonPath('data.tipe.0.nama', 'Brio')->assertJsonPath('data.tipe.0.jumlah_foto', 1)
            ->assertJsonPath('data.tipe.0.sisa_hari', 30)
            ->assertJsonPath('data.unit.0.id', $idUnit)->assertJsonPath('data.unit.0.plat_nomor', 'N 1234 AB')
            ->assertJsonPath('data.unit.0.tipe.nama', 'Avanza')->assertJsonPath('data.unit.0.tipe.terhapus', false);
        $this->assertNotNull($r->json('data.tipe.0.dihapus_pada'));
        $this->assertNotNull($r->json('data.unit.0.dihapus_pada'));

        $this->umurkan(TipeKendaraan::class, $idTipe, 10);
        $this->umurkan(Kendaraan::class, $idUnit, 45);
        $this->panggil('GET', self::SAMPAH)->assertJsonPath('data.tipe.0.sisa_hari', 20)->assertJsonPath('data.unit.0.sisa_hari', 0);

        config(['rental.hari_sampah' => 60]);
        $this->panggil('GET', self::SAMPAH)->assertJsonPath('data.hari_sampah', 60)->assertJsonPath('data.tipe.0.sisa_hari', 50);
    }

    public function test_sampah_hanya_untuk_admin(): void
    {
        [$id] = $this->tipeDiSampah();
        $this->app['auth']->forgetGuards();
        [, $tokenUser] = $this->buatPelanggan();

        $this->panggil('GET', self::SAMPAH, [], $tokenUser)->assertForbidden();
        $this->panggil('POST', self::TIPE."/{$id}/pulihkan", [], $tokenUser)->assertForbidden();
        $this->panggil('DELETE', self::SAMPAH."/tipe/{$id}?konfirmasi=true", [], $tokenUser)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson(self::SAMPAH)->assertUnauthorized();
        $this->assertNotNull(TipeKendaraan::withTrashed()->find($id));
    }

    public function test_pulihkan_tipe_mengembalikan_foto_dan_mencatat_log(): void
    {
        [$id, $path] = $this->tipeDiSampah();

        $this->panggil('POST', self::TIPE."/{$id}/pulihkan")->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.jumlah_foto', 1);

        $this->assertNotNull(TipeKendaraan::find($id));
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'tipe_kendaraan', 'id_objek' => $id, 'aksi' => 'Tipe dipulihkan dari tempat sampah: Brio']);
        $this->panggil('GET', self::SAMPAH)->assertJsonCount(0, 'data.tipe');
        // Sudah aktif: tidak ada yang dipulihkan
        $this->panggil('POST', self::TIPE."/{$id}/pulihkan")->assertStatus(409)->assertJsonPath('code', 'TIDAK_DI_SAMPAH');
        $this->panggil('POST', self::TIPE.'/99999/pulihkan')->assertNotFound();
    }

    public function test_pulihkan_unit_dan_unit_dengan_tipe_di_sampah_ditolak_sampai_tipe_dipulihkan(): void
    {
        [$idUnit, $idTipe] = $this->unitDiSampah();
        $this->panggil('DELETE', self::TIPE."/{$idTipe}")->assertNoContent(); // tipe tanpa unit aktif boleh dibuang

        $this->panggil('POST', self::UNIT."/{$idUnit}/pulihkan")->assertStatus(409)->assertJsonPath('code', 'UNIT_TIPE_DI_SAMPAH')
            ->assertJsonPath('detail.id', $idTipe)->assertJsonPath('detail.nama', 'Avanza');
        $this->assertNull(Kendaraan::find($idUnit));
        $this->panggil('GET', self::SAMPAH)->assertJsonPath('data.unit.0.tipe.terhapus', true);

        $this->panggil('POST', self::TIPE."/{$idTipe}/pulihkan")->assertOk();
        $this->panggil('POST', self::UNIT."/{$idUnit}/pulihkan")->assertOk()
            ->assertJsonPath('data.plat_nomor', 'N 1234 AB')->assertJsonPath('data.detail.no_rangka', 'RANGKA-1')->assertJsonPath('data.tipe.nama', 'Avanza');
        $this->assertNotNull(Kendaraan::find($idUnit));
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'kendaraan', 'id_objek' => $idUnit, 'aksi' => 'Unit dipulihkan dari tempat sampah: N 1234 AB']);
        $this->panggil('POST', self::UNIT."/{$idUnit}/pulihkan")->assertStatus(409)->assertJsonPath('code', 'TIDAK_DI_SAMPAH');
    }

    // ================================================================ bentrok keunikan dengan tempat sampah

    public function test_tambah_tipe_bentrok_dengan_tempat_sampah_memberi_kode_dan_data_lama(): void
    {
        [$id] = $this->tipeDiSampah();
        $this->umurkan(TipeKendaraan::class, $id, 5);

        $r = $this->form(self::TIPE, $this->bodyTipe(['nama' => ' brio ', 'merk' => 'HONDA']))->assertStatus(422)
            ->assertJsonPath('code', 'TIPE_ADA_DI_SAMPAH')
            ->assertJsonPath('detail.jenis', 'tipe')->assertJsonPath('detail.id', $id)
            ->assertJsonPath('detail.nama', 'Brio')->assertJsonPath('detail.merk', 'Honda')
            ->assertJsonPath('detail.kolom_bentrok', ['nama', 'merk'])
            ->assertJsonPath('detail.sisa_hari', 25)
            ->assertJsonStructure(['message', 'code', 'errors' => ['nama'], 'detail' => ['dihapus_pada']]);
        $this->assertStringContainsString('Pulihkan', $r->json('message'));
        $this->assertSame(1, TipeKendaraan::withTrashed()->count());
    }

    public function test_ubah_tipe_menjadi_nama_di_tempat_sampah_ditolak_dan_bentrok_aktif_tetap_kode_lama(): void
    {
        [$idSampah] = $this->tipeDiSampah('Brio');
        $aktif = $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Jazz']))->assertCreated()->json('data.id');
        $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Mobilio']))->assertCreated();

        $this->form(self::TIPE."/{$aktif}", $this->bodyTipe(['nama' => 'Brio']))->assertStatus(422)
            ->assertJsonPath('code', 'TIPE_ADA_DI_SAMPAH')->assertJsonPath('detail.id', $idSampah);
        $this->form(self::TIPE."/{$aktif}", $this->bodyTipe(['nama' => 'Mobilio']))->assertStatus(422)
            ->assertJsonPath('code', 'TIPE_SUDAH_ADA')->assertJsonMissingPath('detail');
    }

    public function test_tambah_dan_ubah_unit_bentrok_dengan_tempat_sampah_memuat_data_lama_per_kolom(): void
    {
        [$idLama, $idTipe] = $this->unitDiSampah();
        $this->umurkan(Kendaraan::class, $idLama, 12);

        $kasus = [
            'plat' => [['plat_nomor' => 'n1234ab'], [], ['plat_nomor']],
            'rangka' => [['plat_nomor' => 'N 9999 ZZ'], ['no_rangka' => ' rangka-1 ', 'no_mesin' => 'M-BARU', 'no_bpkb' => 'B-BARU'], ['no_rangka']],
            'mesin' => [['plat_nomor' => 'N 9999 ZZ'], ['no_rangka' => 'R-BARU', 'no_mesin' => 'mesin-1', 'no_bpkb' => 'B-BARU'], ['no_mesin']],
            'bpkb' => [['plat_nomor' => 'N 9999 ZZ'], ['no_rangka' => 'R-BARU', 'no_mesin' => 'M-BARU', 'no_bpkb' => 'bpkb-1'], ['no_bpkb']],
        ];
        foreach ($kasus as $nama => [$unit, $detail, $kolom]) {
            $this->form(self::UNIT, $this->bodyUnit($idTipe, $unit, $detail + ['no_rangka' => 'R-BARU', 'no_mesin' => 'M-BARU', 'no_bpkb' => 'B-BARU']))
                ->assertStatus(422)->assertJsonPath('code', 'UNIT_ADA_DI_SAMPAH', "kasus {$nama}")
                ->assertJsonPath('detail.jenis', 'unit')->assertJsonPath('detail.id', $idLama)->assertJsonPath('detail.plat_nomor', 'N 1234 AB')
                ->assertJsonPath('detail.kolom_bentrok', $kolom)->assertJsonPath('detail.sisa_hari', 18)
                ->assertJsonStructure(['message', 'code', 'errors', 'detail' => ['dihapus_pada']]);
        }

        // Ubah unit aktif menjadi plat milik unit di tempat sampah
        $aktif = $this->form(self::UNIT, $this->bodyUnit($idTipe, ['plat_nomor' => 'N 5555 CC'], ['no_rangka' => 'R-A', 'no_mesin' => 'M-A', 'no_bpkb' => 'B-A']))->assertCreated()->json('data.id');
        $this->panggil('PUT', self::UNIT."/{$aktif}", $this->bodyUnit($idTipe, ['plat_nomor' => 'N 1234 AB'], ['no_rangka' => 'R-A', 'no_mesin' => 'M-A', 'no_bpkb' => 'B-A']))
            ->assertStatus(422)->assertJsonPath('code', 'UNIT_ADA_DI_SAMPAH')->assertJsonPath('detail.id', $idLama);
        $this->assertSame('N 5555 CC', Kendaraan::find($aktif)->plat_nomor);

        // Bentrok dengan unit AKTIF tetap 422 validasi biasa (tanpa kode sampah)
        $this->form(self::UNIT, $this->bodyUnit($idTipe, ['plat_nomor' => 'N 5555 CC'], ['no_rangka' => 'R-Z', 'no_mesin' => 'M-Z', 'no_bpkb' => 'B-Z']))
            ->assertStatus(422)->assertJsonMissingPath('detail')->assertJsonValidationErrors(['plat_nomor']);
    }

    public function test_hapus_permanen_lalu_buat_ulang_dengan_nilai_sama_berhasil(): void
    {
        [$idTipe] = $this->tipeDiSampah();
        [$idUnit] = $this->unitDiSampah();

        $this->form(self::TIPE, $this->bodyTipe())->assertStatus(422)->assertJsonPath('code', 'TIPE_ADA_DI_SAMPAH');
        $this->panggil('DELETE', self::SAMPAH."/tipe/{$idTipe}?konfirmasi=true")->assertOk();
        $this->form(self::TIPE, $this->bodyTipe())->assertCreated()->assertJsonPath('data.nama', 'Brio');

        $tipeUnit = TipeKendaraan::where('nama', 'Avanza')->value('id_tipe');
        $this->form(self::UNIT, $this->bodyUnit($tipeUnit))->assertStatus(422)->assertJsonPath('code', 'UNIT_ADA_DI_SAMPAH');
        $this->panggil('DELETE', self::SAMPAH."/unit/{$idUnit}?konfirmasi=true")->assertOk();
        $this->form(self::UNIT, $this->bodyUnit($tipeUnit))->assertCreated()->assertJsonPath('data.plat_nomor', 'N 1234 AB');
    }

    public function test_pulihkan_lalu_buat_ulang_ditolak_karena_bentrok_dengan_data_aktif(): void
    {
        [$idTipe] = $this->tipeDiSampah();
        [$idUnit, $tipeUnit] = $this->unitDiSampah();
        $this->panggil('POST', self::TIPE."/{$idTipe}/pulihkan")->assertOk();
        $this->panggil('POST', self::UNIT."/{$idUnit}/pulihkan")->assertOk();

        $this->form(self::TIPE, $this->bodyTipe())->assertStatus(422)->assertJsonPath('code', 'TIPE_SUDAH_ADA')->assertJsonMissingPath('detail');
        $this->form(self::UNIT, $this->bodyUnit($tipeUnit))->assertStatus(422)->assertJsonMissingPath('detail')->assertJsonValidationErrors(['plat_nomor']);
    }

    // ================================================================ hapus permanen satu data

    public function test_hapus_permanen_tipe_menghapus_foto_berkas_dan_mencatat_log(): void
    {
        [$id, $path] = $this->tipeDiSampah();
        Storage::disk('public')->assertExists($path);

        // Tanpa konfirmasi: tidak ada yang berubah
        $this->panggil('DELETE', self::SAMPAH."/tipe/{$id}")->assertStatus(409)->assertJsonPath('code', 'KONFIRMASI_DIPERLUKAN')
            ->assertJsonPath('detail.foto_dihapus', 1);
        $this->panggil('DELETE', self::SAMPAH."/tipe/{$id}", ['konfirmasi' => false])->assertStatus(409);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull(TipeKendaraan::withTrashed()->find($id));

        $this->panggil('DELETE', self::SAMPAH."/tipe/{$id}", ['konfirmasi' => true])->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.foto_dihapus', 1)->assertJsonPath('data.unit_ikut_dihapus', 0);

        $this->assertNull(TipeKendaraan::withTrashed()->find($id));
        $this->assertSame(0, TipeFoto::count());
        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'tipe_kendaraan', 'id_objek' => $id, 'aksi' => 'Tipe dihapus permanen: Brio']);
        $this->panggil('DELETE', self::SAMPAH."/tipe/{$id}?konfirmasi=true")->assertNotFound();
    }

    public function test_hapus_permanen_tipe_ikut_membuang_unit_miliknya_di_tempat_sampah(): void
    {
        [$idUnit, $idTipe] = $this->unitDiSampah();
        $this->panggil('DELETE', self::TIPE."/{$idTipe}")->assertNoContent();

        $this->panggil('DELETE', self::SAMPAH."/tipe/{$idTipe}?konfirmasi=true")->assertOk()->assertJsonPath('data.unit_ikut_dihapus', 1);

        $this->assertNull(Kendaraan::withTrashed()->find($idUnit));
        $this->assertDatabaseMissing('detail_kendaraan', ['id_kendaraan' => $idUnit]);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'kendaraan', 'id_objek' => $idUnit, 'aksi' => 'Unit dihapus permanen: N 1234 AB']);
    }

    public function test_hapus_permanen_unit_menghapus_detail_dan_butuh_konfirmasi(): void
    {
        [$id] = $this->unitDiSampah();

        $this->panggil('DELETE', self::SAMPAH."/unit/{$id}")->assertStatus(409)->assertJsonPath('code', 'KONFIRMASI_DIPERLUKAN')->assertJsonPath('detail.plat_nomor', 'N 1234 AB');
        $this->assertNotNull(Kendaraan::withTrashed()->find($id));

        $this->panggil('DELETE', self::SAMPAH."/unit/{$id}?konfirmasi=true")->assertOk()->assertJsonPath('data.id', $id);
        $this->assertNull(Kendaraan::withTrashed()->find($id));
        $this->assertDatabaseMissing('detail_kendaraan', ['id_kendaraan' => $id]);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'kendaraan', 'id_objek' => $id, 'aksi' => 'Unit dihapus permanen: N 1234 AB']);
    }

    public function test_hapus_permanen_data_aktif_ditolak_dan_tidak_ada_yang_berubah(): void
    {
        $tipe = $this->buatTipe(1, 'Avanza');
        $unit = $tipe->kendaraan()->first();
        $foto = $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Brio']) + ['foto' => [UploadedFile::fake()->image('a.jpg')]])->json('data.id');
        $path = TipeFoto::where('id_tipe', $foto)->value('path_foto');

        $this->panggil('DELETE', self::SAMPAH."/tipe/{$foto}?konfirmasi=true")->assertStatus(409)->assertJsonPath('code', 'TIDAK_DI_SAMPAH');
        $this->panggil('DELETE', self::SAMPAH."/unit/{$unit->id_kendaraan}?konfirmasi=true")->assertStatus(409)->assertJsonPath('code', 'TIDAK_DI_SAMPAH');
        $this->panggil('DELETE', self::SAMPAH.'/tipe/99999?konfirmasi=true')->assertNotFound();
        $this->panggil('DELETE', self::SAMPAH.'/unit/99999?konfirmasi=true')->assertNotFound();

        $this->assertNotNull(TipeKendaraan::find($foto));
        $this->assertNotNull(Kendaraan::find($unit->id_kendaraan));
        Storage::disk('public')->assertExists($path);
    }

    public function test_foto_satuan_tetap_dihapus_permanen_dengan_berkasnya(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->json('data.id');
        $foto = TipeFoto::where('id_tipe', $id)->orderBy('urutan')->first();

        $this->panggil('DELETE', self::TIPE."/{$id}/foto/{$foto->id_foto}")->assertOk()->assertJsonPath('data.jumlah_foto', 1);

        $this->assertNull(TipeFoto::find($foto->id_foto));
        Storage::disk('public')->assertMissing($foto->path_foto);
    }

    // ================================================================ pembersihan terjadwal

    public function test_perintah_bersihkan_membuang_permanen_yang_lewat_n_hari_beserta_berkas(): void
    {
        [$tipeLama, $pathLama] = $this->tipeDiSampah('Brio');
        [$tipeBaru, $pathBaru] = $this->tipeDiSampah('Jazz');
        [$unitLama, $idTipeUnit] = $this->unitDiSampah();
        [$unitBaru] = $this->unitDiSampah(['plat_nomor' => 'N 2222 BB'], ['no_rangka' => 'R2', 'no_mesin' => 'M2', 'no_bpkb' => 'B2']);
        $aktif = $this->buatTipe(1, 'Xenia');

        $this->umurkan(TipeKendaraan::class, $tipeLama, 31);
        $this->umurkan(TipeKendaraan::class, $tipeBaru, 29);
        $this->umurkan(Kendaraan::class, $unitLama, 31);
        $this->umurkan(Kendaraan::class, $unitBaru, 29);

        $this->assertSame(0, Artisan::call('sampah:bersihkan'));

        $this->assertNull(TipeKendaraan::withTrashed()->find($tipeLama));
        $this->assertSame(0, TipeFoto::where('id_tipe', $tipeLama)->count());
        Storage::disk('public')->assertMissing($pathLama);
        $this->assertNull(Kendaraan::withTrashed()->find($unitLama));
        $this->assertDatabaseMissing('detail_kendaraan', ['id_kendaraan' => $unitLama]);

        // Yang belum lewat N hari dan yang aktif tidak disentuh
        $this->assertNotNull(TipeKendaraan::withTrashed()->find($tipeBaru));
        Storage::disk('public')->assertExists($pathBaru);
        $this->assertNotNull(Kendaraan::withTrashed()->find($unitBaru));
        $this->assertNotNull(TipeKendaraan::find($aktif->id_tipe));
        $this->assertSame(1, Kendaraan::where('id_tipe', $aktif->id_tipe)->count());
        $this->assertNotNull(TipeKendaraan::find($idTipeUnit));
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'tipe_kendaraan', 'id_objek' => $tipeLama, 'aksi' => 'Tipe dibuang otomatis dari tempat sampah: Brio', 'pelaku' => 'sistem']);
    }

    public function test_batas_hari_dibaca_dari_config_rental_hari_sampah(): void
    {
        [$id] = $this->tipeDiSampah();
        $this->umurkan(TipeKendaraan::class, $id, 8);

        Artisan::call('sampah:bersihkan');
        $this->assertNotNull(TipeKendaraan::withTrashed()->find($id), 'bawaan 30 hari: belum dibuang');

        config(['rental.hari_sampah' => 7]);
        Artisan::call('sampah:bersihkan');
        $this->assertNull(TipeKendaraan::withTrashed()->find($id));
    }

    public function test_pembersihan_tidak_gagal_total_bila_berkas_foto_sudah_hilang(): void
    {
        [$a, $pathA] = $this->tipeDiSampah('Brio');
        [$b, $pathB] = $this->tipeDiSampah('Jazz');
        Storage::disk('public')->delete($pathA); // berkas hilang lebih dulu
        $this->umurkan(TipeKendaraan::class, $a, 40);
        $this->umurkan(TipeKendaraan::class, $b, 40);

        $this->assertSame(0, Artisan::call('sampah:bersihkan'));

        $this->assertNull(TipeKendaraan::withTrashed()->find($a));
        $this->assertNull(TipeKendaraan::withTrashed()->find($b));
        Storage::disk('public')->assertMissing($pathB);
    }

    public function test_pembersihan_melewati_tipe_yang_masih_punya_unit_belum_kedaluwarsa_dan_satu_data_bermasalah_tidak_menghentikan_yang_lain(): void
    {
        [$unitBaru, $tipeInduk] = $this->unitDiSampah();
        [$bermasalah] = $this->unitDiSampah(['plat_nomor' => 'N 3333 CC'], ['no_rangka' => 'R3', 'no_mesin' => 'M3', 'no_bpkb' => 'B3']);
        [$normal] = $this->unitDiSampah(['plat_nomor' => 'N 4444 DD'], ['no_rangka' => 'R4', 'no_mesin' => 'M4', 'no_bpkb' => 'B4']);

        // Tipe kedaluwarsa tetapi unit miliknya ada yang baru dibuang kemarin: tipe dilewati agar unit itu tidak ikut hilang
        $this->panggil('DELETE', self::TIPE."/{$tipeInduk}")->assertNoContent();
        $this->umurkan(TipeKendaraan::class, $tipeInduk, 60);
        $this->umurkan(Kendaraan::class, $unitBaru, 1);

        // Unit kedaluwarsa yang kini punya riwayat servis (tidak boleh dibuang) tidak menghentikan unit lain
        Perawatan::create(['id_kendaraan' => $bermasalah, 'jenis_servis' => 'Servis', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString(), 'status' => 'selesai']);
        $this->umurkan(Kendaraan::class, $bermasalah, 40);
        $this->umurkan(Kendaraan::class, $normal, 40);

        $this->assertSame(0, Artisan::call('sampah:bersihkan'));

        $this->assertNotNull(TipeKendaraan::withTrashed()->find($tipeInduk));
        $this->assertNotNull(Kendaraan::withTrashed()->find($unitBaru));
        $this->assertNotNull(Kendaraan::withTrashed()->find($bermasalah));
        $this->assertNull(Kendaraan::withTrashed()->find($normal));
    }

    public function test_perintah_terdaftar_di_scheduler_harian(): void
    {
        $acara = collect($this->app->make(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'sampah:bersihkan'));

        $this->assertNotNull($acara, 'sampah:bersihkan harus terdaftar di routes/console.php');
        $this->assertSame('0 2 * * *', $acara->expression);
        $this->assertTrue($acara->withoutOverlapping);
    }

    // ================================================================ unit berriwayat

    /** Unit aktif dengan pesanan selesai, lalu dihapus lewat API. @return array{0:int,1:TipeKendaraan} */
    private function unitBerriwayatDiSampah(): array
    {
        [$budi] = $this->buatPelanggan();
        $tipe = $this->buatTipe(2, 'Avanza');
        $unit = $tipe->kendaraan()->orderBy('id_kendaraan')->first();
        $this->buatPesanan($budi, $tipe, ['status' => 'selesai', 'id_kendaraan' => $unit->id_kendaraan]);
        $this->panggil('DELETE', self::UNIT."/{$unit->id_kendaraan}")->assertNoContent();

        return [$unit->id_kendaraan, $tipe];
    }

    public function test_hapus_permanen_unit_berriwayat_ditolak_409_dan_data_tetap(): void
    {
        [$id] = $this->unitBerriwayatDiSampah();

        $this->panggil('DELETE', self::SAMPAH."/unit/{$id}?konfirmasi=true")->assertStatus(409)
            ->assertJsonPath('code', 'UNIT_PUNYA_RIWAYAT')->assertJsonPath('detail.punya_riwayat', true)->assertJsonPath('detail.id', $id);
        $this->panggil('DELETE', self::SAMPAH."/unit/{$id}")->assertStatus(409)->assertJsonPath('code', 'UNIT_PUNYA_RIWAYAT'); // tanpa konfirmasi pun
        $this->assertNotNull(Kendaraan::onlyTrashed()->find($id));

        // Bisa dipulihkan
        $this->panggil('POST', self::UNIT."/{$id}/pulihkan")->assertOk();
        $this->assertNotNull(Kendaraan::find($id));
    }

    public function test_unit_berriwayat_dilewati_pembersihan_terjadwal_dan_unit_tanpa_riwayat_tetap_dibuang(): void
    {
        [$riwayat] = $this->unitBerriwayatDiSampah();
        [$polos] = $this->unitDiSampah(['plat_nomor' => 'N 9 ZZ'], ['no_rangka' => 'R-9', 'no_mesin' => 'M-9', 'no_bpkb' => 'B-9']);
        $this->umurkan(Kendaraan::class, $riwayat, 400);
        $this->umurkan(Kendaraan::class, $polos, 400);

        $this->assertSame(0, Artisan::call('sampah:bersihkan'));

        $this->assertNotNull(Kendaraan::onlyTrashed()->find($riwayat), 'unit berriwayat tidak pernah dibuang otomatis');
        $this->assertNull(Kendaraan::withTrashed()->find($polos));
    }

    public function test_daftar_sampah_menandai_punya_riwayat_dan_sisa_hari_null_untuk_yang_berriwayat(): void
    {
        [$riwayat] = $this->unitBerriwayatDiSampah();
        [$polos] = $this->unitDiSampah(['plat_nomor' => 'N 9 ZZ'], ['no_rangka' => 'R-9', 'no_mesin' => 'M-9', 'no_bpkb' => 'B-9']);

        $unit = collect($this->panggil('GET', self::SAMPAH)->assertOk()->json('data.unit'))->keyBy('id');
        $this->assertTrue($unit[$riwayat]['punya_riwayat']);
        $this->assertNull($unit[$riwayat]['sisa_hari']);
        $this->assertNotNull($unit[$riwayat]['dihapus_pada']);
        $this->assertFalse($unit[$polos]['punya_riwayat']);
        $this->assertIsInt($unit[$polos]['sisa_hari']);
    }

    public function test_bentrok_dengan_unit_berriwayat_di_sampah_memuat_punya_riwayat_true(): void
    {
        [$riwayat, $tipe] = $this->unitBerriwayatDiSampah();
        $plat = Kendaraan::onlyTrashed()->find($riwayat)->plat_nomor;
        [$polos] = $this->unitDiSampah(['plat_nomor' => 'N 9 ZZ'], ['no_rangka' => 'R-9', 'no_mesin' => 'M-9', 'no_bpkb' => 'B-9']);
        $baru = ['no_rangka' => 'BARU-1', 'no_mesin' => 'BARU-2', 'no_bpkb' => 'BARU-3'];

        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => $plat], $baru))
            ->assertStatus(422)->assertJsonPath('code', 'UNIT_ADA_DI_SAMPAH')
            ->assertJsonPath('detail.id', $riwayat)->assertJsonPath('detail.punya_riwayat', true)->assertJsonPath('detail.sisa_hari', null);

        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 9 ZZ'], $baru))
            ->assertStatus(422)->assertJsonPath('detail.id', $polos)->assertJsonPath('detail.punya_riwayat', false);
    }

    public function test_riwayat_pesanan_dan_servis_tetap_menampilkan_unit_yang_di_soft_delete(): void
    {
        [$id, $tipe] = $this->unitBerriwayatDiSampah();
        $plat = Kendaraan::onlyTrashed()->find($id)->plat_nomor;

        $pesanan = Pemesanan::where('id_kendaraan', $id)->first();
        $this->assertSame($plat, $pesanan->kendaraan?->plat_nomor);
        $this->panggil('GET', '/api/v1/admin/pemesanan/'.$pesanan->id_pemesanan)->assertOk()->assertJsonFragment(['plat_nomor' => $plat]);

        $servis = Perawatan::create(['id_kendaraan' => $id, 'jenis_servis' => 'Servis', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString(), 'status' => 'selesai']);
        $this->assertSame($plat, $servis->kendaraan->plat_nomor);
        $this->assertSame($tipe->nama, Kendaraan::onlyTrashed()->find($id)->tipe->nama);
    }
}
