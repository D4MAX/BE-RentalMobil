<?php

namespace Tests\Feature;

use App\Models\Jaminan;
use App\Models\Kendaraan;
use App\Models\Pelanggan;
use App\Models\Pemesanan;
use App\Models\SerahTerima;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** GET .../unit-tersedia dan POST .../serah-terima (Spek API bagian 9). */
class AdminSerahTerimaTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/admin/pemesanan';

    private string $token;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        [$this->admin, $this->token] = $this->buatAdmin();
    }

    private function panggil(string $metode, string $url, array $isi = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($metode, $url, $isi);
    }

    /**
     * Serah terima selalu multipart/form-data. $foto = nama item jaminan yang difotokan (null: set lengkap Malang Raya);
     * kunci berbentuk string diperlakukan sebagai berkas siap pakai.
     *
     * @param  list<string>|null  $foto
     */
    private function serah(int $id, array $isi, ?array $foto = null, ?string $token = null, array $tambahan = [])
    {
        $berkas = [];
        foreach ($foto ?? ['ktp_asli', 'sim_c_asli', 'dokumen_tambahan', 'motor_stnk'] as $item) {
            $berkas['foto_jaminan'][$item] = UploadedFile::fake()->image("{$item}.jpg");
        }
        $berkas += $tambahan;

        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars([
            'Accept' => 'application/json', 'Authorization' => 'Bearer '.($token ?? $this->token), 'Content-Type' => 'multipart/form-data',
        ]);

        return $this->call('POST', self::API."/{$id}/serah-terima", $isi, [], $berkas, $server);
    }

    /** Body lengkap lepas kunci (tanpa berkas); jenis dokumen tambahan tetap berupa teks. */
    private function bodyKunci(int $idUnit, array $ubah = []): array
    {
        return $ubah + [
            'id_kendaraan' => $idUnit, 'no_identitas' => '3573010000000009', 'odometer_awal' => 45800, 'bbm_awal' => 'penuh',
            'catatan_kondisi' => 'Ada baret halus di kap',
            'jaminan' => ['dokumen_tambahan' => 'kk'],
        ];
    }

    private function dikonfirmasi(?string $kota = 'Kota Malang', array $ubah = [], int $unit = 2): Pemesanan
    {
        [$p] = $this->buatPelanggan('p'.uniqid().'@mail.test', $kota);

        return $this->buatPesanan($p, $this->buatTipe($unit, 'T'.uniqid()), ['status' => 'dikonfirmasi'] + $ubah);
    }

    // ---- unit-tersedia ----

    public function test_unit_tersedia_hanya_unit_bebas_dari_tipe_yang_sama(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(4);
        $lain = $this->buatTipe(1, 'Lain');
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();
        $unit[1]->update(['status' => 'dalam_perawatan']);
        $unit[2]->update(['status' => 'tidak_tersedia']);

        // unit[3] sedang disewa pesanan lain
        $this->buatPesanan($budi, $t, ['status' => 'berjalan', 'id_kendaraan' => $unit[3]->id_kendaraan]);
        $p = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);

        $r = $this->panggil('GET', self::API."/{$p->id_pemesanan}/unit-tersedia")->assertOk();

        $this->assertSame([$unit[0]->id_kendaraan], $r->json('data.unit.*.id'));
        $r->assertJsonPath('data.jumlah', 1)->assertJsonPath('data.unit.0', [
            'id' => $unit[0]->id_kendaraan, 'plat_nomor' => $unit[0]->plat_nomor, 'warna' => 'Putih', 'km_terakhir' => 0,
        ]);
        $this->assertNotContains($lain->kendaraan()->first()->id_kendaraan, $r->json('data.unit.*.id'));
    }

    public function test_unit_tersedia_status_salah_dan_tidak_ada(): void
    {
        [$budi] = $this->buatPelanggan();
        $menunggu = $this->buatPesanan($budi, $this->buatTipe(1));

        $this->panggil('GET', self::API."/{$menunggu->id_pemesanan}/unit-tersedia")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->panggil('GET', self::API.'/9999/unit-tersedia')->assertNotFound();
    }

    // ---- serah terima lepas kunci ----

    public function test_lepas_kunci_malang_raya_berhasil_menyimpan_semuanya_dan_foto_jaminan_privat(): void
    {
        $p = $this->dikonfirmasi('Kota Malang');
        $unit = $p->tipe->kendaraan()->orderBy('id_kendaraan')->first();

        $this->serah($p->id_pemesanan, $this->bodyKunci($unit->id_kendaraan))->assertOk()
            ->assertJsonPath('data', ['status' => 'berjalan', 'plat_nomor' => $unit->plat_nomor, 'jaminan_status' => 'ditahan']);

        $p->refresh();
        $this->assertSame(['berjalan', $unit->id_kendaraan, $this->admin->id_akun], [$p->status, $p->id_kendaraan, $p->id_admin]);
        $st = SerahTerima::where('id_pemesanan', $p->id_pemesanan)->sole();
        $this->assertSame([$unit->id_kendaraan, 45800, 'penuh', 'Ada baret halus di kap', $this->admin->id_akun],
            [$st->id_kendaraan, $st->odometer_awal, $st->bbm_awal, $st->catatan_kondisi, $st->id_admin]);
        $this->assertNull($st->foto_kondisi, 'Foto jaminan tidak boleh bercampur dengan foto kondisi');
        $jaminan = Jaminan::where('id_pemesanan', $p->id_pemesanan)->get();
        $this->assertEqualsCanonicalizing(['ktp_asli', 'sim_c_asli', 'dokumen_tambahan:kk', 'motor_stnk'], $jaminan->pluck('jenis')->all());
        $this->assertSame(0, Jaminan::where('dikembalikan', true)->count());
        foreach ($jaminan as $j) {
            $this->assertStringStartsWith("jaminan/{$p->id_pemesanan}/", $j->foto);
            Storage::disk('local')->assertExists($j->foto);     // disk privat
            Storage::disk('public')->assertMissing($j->foto);   // bukan di penyimpanan publik
        }
        $this->assertCount(4, array_unique($jaminan->pluck('foto')->all()), 'Tiap item punya berkasnya sendiri');
        $this->assertSame('3573010000000009', Pelanggan::find($p->id_pelanggan)->no_identitas);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'pelaku' => 'admin', 'aksi' => 'Unit diserahkan: '.$unit->plat_nomor]);
    }

    public function test_foto_jaminan_wajib_menurut_domisili_dan_domisili_disesuaikan_dicatat(): void
    {
        $malang = $this->dikonfirmasi('Kota Malang');
        $unitM = $malang->tipe->kendaraan()->first();

        // Malang Raya tanpa foto motor dan STNK
        $r = $this->serah($malang->id_pemesanan, $this->bodyKunci($unitM->id_kendaraan), ['ktp_asli', 'sim_c_asli', 'dokumen_tambahan'])
            ->assertStatus(422)->assertJsonValidationErrors('foto_jaminan.motor_stnk');
        $this->assertSame('Foto motor dan STNK wajib diunggah', $r->json('errors')['foto_jaminan.motor_stnk'][0]);
        $this->assertArrayNotHasKey('foto_jaminan.ktp_asli', $r->json('errors'));

        // KTP asli ternyata luar Malang Raya: yang dituntut berganti jadi foto booking transportasi dan hotel
        $disesuaikan = $this->bodyKunci($unitM->id_kendaraan, ['domisili_disesuaikan' => 'luar_malang_raya']);
        $r = $this->serah($malang->id_pemesanan, $disesuaikan)->assertStatus(422)
            ->assertJsonValidationErrors(['foto_jaminan.booking_transportasi', 'foto_jaminan.booking_hotel']);
        $this->assertArrayNotHasKey('foto_jaminan.motor_stnk', $r->json('errors'));
        $this->assertSame('malang_raya', $malang->fresh()->domisili, 'Gagal validasi tidak mengubah domisili');

        $disesuaikan['jaminan'] = ['dokumen_tambahan' => 'npwp'];
        $this->serah($malang->id_pemesanan, $disesuaikan, ['ktp_asli', 'sim_c_asli', 'dokumen_tambahan', 'booking_transportasi', 'booking_hotel'])->assertOk();

        $this->assertSame('luar_malang_raya', $malang->fresh()->domisili);
        $this->assertEqualsCanonicalizing(['ktp_asli', 'sim_c_asli', 'dokumen_tambahan:npwp', 'booking_transportasi', 'booking_hotel'],
            Jaminan::where('id_pemesanan', $malang->id_pemesanan)->pluck('jenis')->all());
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $malang->id_pemesanan, 'aksi' => 'Domisili pesanan disesuaikan dari malang_raya ke luar_malang_raya (KTP asli berbeda)']);

        // Pesanan luar Malang Raya dari awal: foto motor tidak dituntut, foto booking dituntut
        $luar = $this->dikonfirmasi('Kota Surabaya', ['domisili' => 'luar_malang_raya']);
        $unitL = $luar->tipe->kendaraan()->first();
        $r = $this->serah($luar->id_pemesanan, $this->bodyKunci($unitL->id_kendaraan), ['ktp_asli', 'sim_c_asli', 'dokumen_tambahan', 'booking_transportasi'])
            ->assertStatus(422)->assertJsonValidationErrors('foto_jaminan.booking_hotel');
        $this->assertSame(['foto_jaminan.booking_hotel'], array_keys($r->json('errors')));
    }

    public function test_tiap_foto_jaminan_wajib_dengan_pesan_per_item_dan_pesanan_tidak_berubah(): void
    {
        $p = $this->dikonfirmasi();
        $u = $p->tipe->kendaraan()->first()->id_kendaraan;

        $r = $this->serah($p->id_pemesanan, [], [])->assertStatus(422)->assertJsonPath('code', 'VALIDASI_GAGAL')
            ->assertJsonValidationErrors(['id_kendaraan', 'no_identitas', 'odometer_awal', 'bbm_awal', 'jaminan.dokumen_tambahan',
                'foto_jaminan.ktp_asli', 'foto_jaminan.sim_c_asli', 'foto_jaminan.dokumen_tambahan', 'foto_jaminan.motor_stnk']);
        $this->assertSame('Foto KTP asli wajib diunggah', $r->json('errors')['foto_jaminan.ktp_asli'][0]);
        $this->assertSame('Foto SIM C asli wajib diunggah', $r->json('errors')['foto_jaminan.sim_c_asli'][0]);
        $this->assertSame('Foto dokumen tambahan wajib diunggah', $r->json('errors')['foto_jaminan.dokumen_tambahan'][0]);

        // Hilang satu per satu: hanya item itu yang dilaporkan
        foreach (['ktp_asli', 'sim_c_asli', 'dokumen_tambahan', 'motor_stnk'] as $hilang) {
            $ada = array_values(array_diff(['ktp_asli', 'sim_c_asli', 'dokumen_tambahan', 'motor_stnk'], [$hilang]));
            $r = $this->serah($p->id_pemesanan, $this->bodyKunci($u), $ada)->assertStatus(422);
            $this->assertSame(["foto_jaminan.{$hilang}"], array_keys($r->json('errors')));
        }

        $this->serah($p->id_pemesanan, $this->bodyKunci($u, ['jaminan' => ['dokumen_tambahan' => 'sim_lain']]))
            ->assertStatus(422)->assertJsonValidationErrors('jaminan.dokumen_tambahan');
        $this->serah($p->id_pemesanan, $this->bodyKunci($u, ['bbm_awal' => 'banjir']))->assertStatus(422)->assertJsonValidationErrors('bbm_awal');

        $this->assertSame('dikonfirmasi', $p->fresh()->status, 'Validasi gagal tidak mengubah apa pun');
        $this->assertSame(0, SerahTerima::count() + Jaminan::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Penolakan tidak meninggalkan berkas');
    }

    public function test_foto_jaminan_hanya_jpg_png_dan_maksimal_2_mb(): void
    {
        $p = $this->dikonfirmasi();
        $u = $p->tipe->kendaraan()->first()->id_kendaraan;
        $baik = fn () => UploadedFile::fake()->image('ok.jpg');

        $coba = fn ($ktp) => $this->serah($p->id_pemesanan, $this->bodyKunci($u), [], null, ['foto_jaminan' => [
            'ktp_asli' => $ktp, 'sim_c_asli' => $baik(), 'dokumen_tambahan' => $baik(), 'motor_stnk' => $baik(),
        ]]);

        $r = $coba(UploadedFile::fake()->image('ktp.gif'))->assertStatus(422);
        $this->assertSame('Foto KTP asli harus berformat jpg atau png', $r->json('errors')['foto_jaminan.ktp_asli'][0]);
        $r = $coba(UploadedFile::fake()->create('ktp.pdf', 100, 'application/pdf'))->assertStatus(422);
        $this->assertSame('Foto KTP asli harus berformat jpg atau png', $r->json('errors')['foto_jaminan.ktp_asli'][0]);
        $r = $coba(UploadedFile::fake()->image('besar.jpg')->size(2049))->assertStatus(422);
        $this->assertSame('Foto KTP asli maksimal 2 MB', $r->json('errors')['foto_jaminan.ktp_asli'][0]);
        $this->assertSame('dikonfirmasi', $p->fresh()->status);

        // Tepat 2 MB dan png diterima
        $coba(UploadedFile::fake()->image('ktp.png')->size(2048))->assertOk();
    }

    public function test_hanya_menerima_multipart_json_ditolak_415(): void
    {
        $p = $this->dikonfirmasi();
        $unit = $p->tipe->kendaraan()->first();

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/serah-terima", $this->bodyKunci($unit->id_kendaraan))
            ->assertStatus(415)->assertJsonPath('code', 'FORMAT_TIDAK_DIDUKUNG');
        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $this->assertSame(0, SerahTerima::count() + Jaminan::count());

        // Berlaku juga untuk sewa dengan sopir
        $sopir = $this->dikonfirmasi('Kota Malang', ['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null, 'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun']);
        $this->panggil('POST', self::API."/{$sopir->id_pemesanan}/serah-terima", ['id_kendaraan' => $sopir->tipe->kendaraan()->first()->id_kendaraan])
            ->assertStatus(415);
    }

    // ---- serah terima dengan sopir ----

    public function test_dengan_sopir_tanpa_foto_jaminan_cukup_memilih_unit(): void
    {
        $p = $this->dikonfirmasi('Kota Malang', ['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null, 'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun']);
        $unit = $p->tipe->kendaraan()->first();

        $this->serah($p->id_pemesanan, ['id_kendaraan' => $unit->id_kendaraan, 'catatan_kondisi' => 'Bersih'], [])->assertOk()
            ->assertJsonPath('data', ['status' => 'berjalan', 'plat_nomor' => $unit->plat_nomor, 'jaminan_status' => null]);

        $this->assertSame('berjalan', $p->fresh()->status);
        $this->assertSame(0, Jaminan::count(), 'Dengan sopir tidak memakai jaminan');
        $this->assertNull(Pelanggan::find($p->id_pelanggan)->no_identitas);
    }

    public function test_jaminan_dan_foto_jaminan_yang_dikirim_untuk_dengan_sopir_diabaikan(): void
    {
        $p = $this->dikonfirmasi('Kota Malang', ['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null, 'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun']);
        $unit = $p->tipe->kendaraan()->first();

        $this->serah($p->id_pemesanan, $this->bodyKunci($unit->id_kendaraan))->assertOk()->assertJsonPath('data.jaminan_status', null);

        $this->assertSame(0, Jaminan::count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Foto jaminan untuk dengan sopir tidak disimpan');
    }

    // ---- galat bisnis ----

    public function test_status_salah_unit_tidak_bebas_dan_unit_salah_tipe(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(3);
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();

        $menunggu = $this->buatPesanan($budi, $t);
        $this->serah($menunggu->id_pemesanan, $this->bodyKunci($unit[0]->id_kendaraan))->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $p = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        // Unit 0 sedang disewa pesanan lain, unit 1 dalam perawatan
        $this->buatPesanan($budi, $t, ['status' => 'berjalan', 'id_kendaraan' => $unit[0]->id_kendaraan]);
        $unit[1]->update(['status' => 'dalam_perawatan']);
        foreach ([$unit[0], $unit[1]] as $u) {
            $this->serah($p->id_pemesanan, $this->bodyKunci($u->id_kendaraan))->assertStatus(409)
                ->assertJsonPath('code', 'UNIT_SUDAH_DIPAKAI')->assertJsonPath('message', 'Unit yang dipilih sudah tidak bebas');
        }

        // Tipe lain dan id yang tidak ada: 422
        $lain = $this->buatTipe(1, 'Lain')->kendaraan()->first();
        $this->serah($p->id_pemesanan, $this->bodyKunci($lain->id_kendaraan))->assertStatus(422)->assertJsonValidationErrors('id_kendaraan');
        $this->serah($p->id_pemesanan, $this->bodyKunci(99999))->assertStatus(422)->assertJsonValidationErrors('id_kendaraan');
        $this->serah(99999, $this->bodyKunci($unit[2]->id_kendaraan))->assertNotFound();

        $this->assertSame('dikonfirmasi', $p->fresh()->status);

        // Unit 2 bebas: berhasil; diserahkan dua kali: STATUS_TIDAK_VALID
        $this->serah($p->id_pemesanan, $this->bodyKunci($unit[2]->id_kendaraan))->assertOk();
        $this->serah($p->id_pemesanan, $this->bodyKunci($unit[2]->id_kendaraan))->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
    }

    public function test_unit_yang_sudah_diserahkan_tidak_muncul_lagi_dan_tidak_bisa_dipilih_pesanan_lain(): void
    {
        [$a] = $this->buatPelanggan('a@mail.test');
        [$b] = $this->buatPelanggan('b@mail.test');
        $t = $this->buatTipe(2);
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();
        $p1 = $this->buatPesanan($a, $t, ['status' => 'dikonfirmasi']);
        $p2 = $this->buatPesanan($b, $t, ['status' => 'dikonfirmasi']);

        $this->serah($p1->id_pemesanan, $this->bodyKunci($unit[0]->id_kendaraan))->assertOk();

        $this->assertSame([$unit[1]->id_kendaraan], $this->panggil('GET', self::API."/{$p2->id_pemesanan}/unit-tersedia")->json('data.unit.*.id'));
        $this->serah($p2->id_pemesanan, $this->bodyKunci($unit[0]->id_kendaraan))->assertStatus(409)->assertJsonPath('code', 'UNIT_SUDAH_DIPAKAI');
        $this->assertSame(1, Pemesanan::where('id_kendaraan', $unit[0]->id_kendaraan)->where('status', 'berjalan')->count());
    }

    // ---- foto kondisi dan akses ----

    public function test_foto_kondisi_opsional_tersimpan_terpisah_dari_foto_jaminan(): void
    {
        $p = $this->dikonfirmasi();
        $unit = $p->tipe->kendaraan()->first();

        $this->serah($p->id_pemesanan, $this->bodyKunci($unit->id_kendaraan), null, null, ['foto_kondisi' => [
            UploadedFile::fake()->image('depan.jpg'), UploadedFile::fake()->image('belakang.png'),
        ]])->assertOk()->assertJsonPath('data.status', 'berjalan');

        $paths = SerahTerima::where('id_pemesanan', $p->id_pemesanan)->sole()->foto_kondisi;
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertStringStartsWith("serah-terima/{$p->id_pemesanan}/", $path);
            Storage::disk('local')->assertExists($path);
        }
        $this->assertCount(4, Jaminan::whereNotNull('foto')->get());
        $this->panggil('GET', self::API."/{$p->id_pemesanan}")->assertJsonPath('data.serah_terima.jumlah_foto', 2);
    }

    public function test_galat_di_tengah_proses_menghapus_semua_berkas_yang_sudah_ditulis_dan_membatalkan_data(): void
    {
        $p = $this->dikonfirmasi();
        $unit = $p->tipe->kendaraan()->first();

        // Galat tak terduga SETELAH foto jaminan dan foto kondisi ditulis ke disk, saat status pesanan diubah
        Pemesanan::updating(function () {
            $this->assertNotSame([], Storage::disk('local')->allFiles(), 'Berkas sudah ada di disk saat galat terjadi');

            throw new \RuntimeException('galat uji');
        });

        try {
            $this->serah($p->id_pemesanan, $this->bodyKunci($unit->id_kendaraan), null, null, ['foto_kondisi' => [UploadedFile::fake()->image('k.jpg')]])
                ->assertStatus(500);
        } finally {
            Pemesanan::flushEventListeners();
        }

        $this->assertSame([], Storage::disk('local')->allFiles(), 'Tidak ada berkas yatim');
        $this->assertSame('dikonfirmasi', $p->fresh()->status);
        $this->assertSame(0, SerahTerima::count() + Jaminan::count(), 'Data ikut dibatalkan');
    }

    public function test_foto_kondisi_bukan_gambar_ditolak_dan_user_biasa_dilarang(): void
    {
        $p = $this->dikonfirmasi();
        $unit = $p->tipe->kendaraan()->first();

        $this->serah($p->id_pemesanan, $this->bodyKunci($unit->id_kendaraan), null, null, ['foto_kondisi' => [UploadedFile::fake()->create('virus.exe', 10)]])
            ->assertStatus(422)->assertJsonValidationErrors('foto_kondisi.0');
        $this->assertSame('dikonfirmasi', $p->fresh()->status);

        [, $tokenUser] = $this->buatPelanggan('user@mail.test');
        $this->serah($p->id_pemesanan, $this->bodyKunci($unit->id_kendaraan), null, $tokenUser)->assertForbidden();
    }
}
