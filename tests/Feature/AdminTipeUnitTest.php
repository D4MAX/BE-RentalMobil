<?php

namespace Tests\Feature;

use App\Models\DetailKendaraan;
use App\Models\Kendaraan;
use App\Models\Perawatan;
use App\Models\TipeFoto;
use App\Models\TipeKendaraan;
use App\Support\TeksNama;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** /admin/tipe dan /admin/unit (Spek API bagian 8). */
class AdminTipeUnitTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const TIPE = '/api/v1/admin/tipe';

    private const UNIT = '/api/v1/admin/unit';

    private string $token;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        [$this->admin, $this->token] = $this->buatAdmin();
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

    /** Nomor rangka, mesin, dan BPKB unik per plat (plat bawaan tetap MH4001, L15001, BPKB-001). */
    private function bodyUnit(int $idTipe, array $ubah = []): array
    {
        $sufiks = isset($ubah['plat_nomor']) ? '-'.preg_replace('/\W+/', '', $ubah['plat_nomor']) : '';

        return $ubah + ['id_tipe' => $idTipe, 'plat_nomor' => 'N 1234 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'km_terakhir' => 45800,
            'detail' => ['nama_pemilik' => 'PT GantengnyaJatim', 'status_kepemilikan' => 'milik_perusahaan', 'no_rangka' => 'MH4001'.$sufiks, 'no_mesin' => 'L15001'.$sufiks,
                'no_bpkb' => 'BPKB-001'.$sufiks, 'masa_berlaku_stnk' => '2027-03-15', 'catatan' => '']];
    }

    // ================================================================ tipe

    public function test_hanya_admin_boleh_mengelola_tipe_dan_unit(): void
    {
        [, $tokenUser] = $this->buatPelanggan();
        $tipe = $this->buatTipe(1);

        foreach ([['GET', self::TIPE], ['POST', self::TIPE], ['POST', self::TIPE."/{$tipe->id_tipe}"], ['DELETE', self::TIPE."/{$tipe->id_tipe}"],
            ['GET', self::UNIT], ['POST', self::UNIT], ['PATCH', self::UNIT.'/1/status']] as [$metode, $url]) {
            $this->panggil($metode, $url, [], $tokenUser)->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson(self::TIPE)->assertUnauthorized();
    }

    public function test_daftar_dan_detail_tipe_dengan_jumlah_unit(): void
    {
        $a = $this->buatTipe(3, 'Avanza');
        $b = $this->buatTipe(0, 'Brio');

        $r = $this->panggil('GET', self::TIPE)->assertOk();
        $this->assertSame(['Avanza', 'Brio'], $r->json('data.*.nama'));
        $this->assertSame([3, 0], $r->json('data.*.jumlah_unit'));
        $r->assertJsonPath('data.0.harga_sopir_per_hari', 650000)->assertJsonPath('data.0.jumlah_foto', 0);

        $this->panggil('GET', self::TIPE."/{$a->id_tipe}")->assertOk()->assertJsonPath('data.id', $a->id_tipe)->assertJsonPath('data.jumlah_unit', 3);
        $this->panggil('GET', self::TIPE.'/9999')->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
    }

    public function test_tambah_tipe_dengan_tiga_foto_multipart(): void
    {
        $r = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [
            UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.jpeg'),
        ]])->assertCreated();

        $id = $r->json('data.id');
        $r->assertJsonPath('data.nama', 'Brio')->assertJsonPath('data.jumlah_foto', 3)->assertJsonPath('data.jumlah_unit', 0);
        $this->assertCount(3, $r->json('data.foto'));
        $this->assertSame([1, 2, 3], TipeFoto::where('id_tipe', $id)->orderBy('urutan')->pluck('urutan')->all());
        foreach (TipeFoto::where('id_tipe', $id)->pluck('path_foto') as $path) {
            Storage::disk('public')->assertExists($path);
        }
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'tipe_kendaraan', 'id_objek' => $id, 'id_akun' => $this->admin->id_akun, 'aksi' => 'Tipe ditambah: Brio']);

        // Tampil di katalog publik beserta fotonya
        $this->buatUnitUntukTipe($id);
        $mulai = now()->addDays(3)->toDateString();
        $this->flushHeaders();
        $this->getJson("/api/v1/katalog/tipe/{$id}?tgl_mulai={$mulai}&durasi=1")->assertOk()->assertJsonCount(3, 'data.foto');
    }

    private function buatUnitUntukTipe(int $idTipe): void
    {
        Kendaraan::create(['id_tipe' => $idTipe, 'plat_nomor' => 'N 9 ZZ', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
    }

    public function test_bahan_bakar_hanya_bensin_atau_diesel(): void
    {
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['nama' => 'Listrik', 'bahan_bakar' => 'listrik']))->assertStatus(422)->assertJsonValidationErrors('bahan_bakar');
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['nama' => 'Dakar', 'bahan_bakar' => 'diesel']))->assertCreated()->assertJsonPath('data.bahan_bakar', 'diesel');
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['nama' => 'Jazz', 'bahan_bakar' => 'bensin']))->assertCreated()->assertJsonPath('data.bahan_bakar', 'bensin');

        // Ubah tipe ke listrik juga ditolak, dan nilai lama tidak berubah
        $id = $this->panggil('GET', self::TIPE)->json('data.0.id');
        $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Dakar', 'bahan_bakar' => 'listrik']))->assertStatus(422)->assertJsonValidationErrors('bahan_bakar');
        $this->assertSame(2, TipeKendaraan::whereIn('bahan_bakar', ['bensin', 'diesel'])->count());
    }

    public function test_validasi_tipe(): void
    {
        $this->panggil('POST', self::TIPE, [])->assertStatus(422)->assertJsonPath('code', 'VALIDASI_GAGAL')
            ->assertJsonValidationErrors(['nama', 'merk', 'kursi', 'bahan_bakar']);
        // Minimal satu tarif
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['harga_sopir_per_hari' => null, 'harga_lepas_kunci_manual' => null, 'harga_lepas_kunci_matic' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('harga_sopir_per_hari');
        // Satu tarif saja sudah cukup
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['harga_sopir_per_hari' => null, 'harga_lepas_kunci_matic' => null]))->assertCreated();

        $this->panggil('POST', self::TIPE, $this->bodyTipe(['bahan_bakar' => 'air']))->assertStatus(422)->assertJsonValidationErrors('bahan_bakar');
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['bahan_bakar' => 'listrik']))->assertStatus(422)->assertJsonValidationErrors('bahan_bakar');
        $this->panggil('POST', self::TIPE, $this->bodyTipe(['kursi' => 'lima']))->assertStatus(422)->assertJsonValidationErrors('kursi');

        // Foto: maksimal 3, jpg atau png, maksimal 2 MB
        $this->form(self::TIPE, $this->bodyTipe() + ['foto' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), [1, 2, 3, 4])])
            ->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('besar.jpg')->size(3000)]])
            ->assertStatus(422)->assertJsonValidationErrors('foto.0');
        $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->create('dok.pdf', 100, 'application/pdf')]])
            ->assertStatus(422)->assertJsonValidationErrors('foto.0');
        $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('anim.gif')]])->assertStatus(422)->assertJsonValidationErrors('foto.0');

        $this->assertSame(1, TipeKendaraan::count(), 'Hanya yang valid yang tersimpan');
    }

    public function test_ubah_tipe_lewat_post_multipart_tanpa_foto_foto_lama_tetap(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('lama1.jpg'), UploadedFile::fake()->image('lama2.jpg')]])->json('data.id');
        $lama = TipeFoto::where('id_tipe', $id)->pluck('path_foto')->all();

        // Tanpa foto: foto lama tetap, tarif berubah (POST multipart tanpa _method)
        $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Brio RS', 'harga_lepas_kunci_manual' => 320000]))->assertOk()
            ->assertJsonPath('data.nama', 'Brio RS')->assertJsonPath('data.harga_lepas_kunci_manual', 320000)->assertJsonPath('data.jumlah_foto', 2);
        foreach ($lama as $path) {
            Storage::disk('public')->assertExists($path);
        }

        // Kolom foto[] kosong (teks kosong dari form) sama dengan tidak dikirim
        $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Brio RS']) + ['foto' => ['']])->assertOk()->assertJsonPath('data.jumlah_foto', 2);
        $this->assertSame($lama, TipeFoto::where('id_tipe', $id)->orderBy('urutan')->pluck('path_foto')->all());

        // Tarif yang dikosongkan berarti jenis sewa itu tidak ditawarkan lagi
        $this->form(self::TIPE."/{$id}", $this->bodyTipe(['harga_sopir_per_hari' => '']))->assertOk()->assertJsonPath('data.harga_sopir_per_hari', null);
        $this->form(self::TIPE.'/9999', $this->bodyTipe())->assertNotFound();

        // PUT sudah dihapus: baik JSON maupun spoofing _method tidak lagi mengubah tipe
        $this->panggil('PUT', self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Lewat PUT']))->assertStatus(405);
        $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Lewat spoof']) + ['_method' => 'PUT'])->assertStatus(405);
        $this->assertSame('Brio', TipeKendaraan::find($id)->nama, 'Nama tetap dari ubah terakhir lewat POST');
    }

    public function test_foto_baru_ditambahkan_ke_foto_lama_dengan_urutan_berlanjut(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('a.jpg')]])->json('data.id');
        $a = TipeFoto::where('id_tipe', $id)->sole();

        $r = $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Brio RS']) + ['foto' => [UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.jpg')]])
            ->assertOk()->assertJsonPath('data.jumlah_foto', 3)->assertJsonPath('data.nama', 'Brio RS');

        $semua = TipeFoto::where('id_tipe', $id)->orderBy('urutan')->get();
        $this->assertSame([1, 2, 3], $semua->pluck('urutan')->map(fn ($u) => (int) $u)->all());
        $this->assertSame($a->id_foto, $semua[0]->id_foto, 'Foto lama tetap di urutan pertama');
        $this->assertSame($a->path_foto, $semua[0]->path_foto);
        foreach ($semua as $f) {
            Storage::disk('public')->assertExists($f->path_foto);
        }
        $this->assertCount(3, $r->json('data.foto'));
    }

    public function test_foto_melebihi_tiga_total_ditolak_422_dan_tidak_ada_yang_berubah(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->json('data.id');
        $sebelum = TipeFoto::where('id_tipe', $id)->orderBy('urutan')->pluck('path_foto')->all();
        $berkasSebelum = Storage::disk('public')->allFiles();

        // Sudah 2, tambah 2 = 4: ditolak, termasuk perubahan nama (satu transaksi)
        $r = $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'Tidak Boleh']) + ['foto' => [UploadedFile::fake()->image('c.jpg'), UploadedFile::fake()->image('d.jpg')]])
            ->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->assertSame('Foto tinggal bisa ditambah 1 lagi (sudah ada 2, maksimal 3).', $r->json('errors.foto.0'));

        $this->assertSame('Brio', TipeKendaraan::find($id)->nama, 'Perubahan data ikut dibatalkan');
        $this->assertSame($sebelum, TipeFoto::where('id_tipe', $id)->orderBy('urutan')->pluck('path_foto')->all());
        $this->assertEqualsCanonicalizing($berkasSebelum, Storage::disk('public')->allFiles(), 'Tidak ada berkas yatim');

        // Lebih dari 3 dalam satu permintaan ditolak oleh aturan per permintaan; tepat sampai 3 diterima
        $empat = fn () => array_map(fn ($n) => UploadedFile::fake()->image("x{$n}.jpg"), [1, 2, 3, 4]);
        $r = $this->form(self::TIPE."/{$id}", $this->bodyTipe() + ['foto' => $empat()])->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->assertSame('Foto maksimal 3 berkas.', $r->json('errors.foto.0'));
        // Membuat tipe baru dengan lebih dari 3 foto: pesan yang sama, tidak ada tipe tersimpan
        $jumlah = TipeKendaraan::count();
        $r = $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Baru']) + ['foto' => $empat()])->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->assertSame('Foto maksimal 3 berkas.', $r->json('errors.foto.0'));
        $this->assertSame($jumlah, TipeKendaraan::count());
        $this->form(self::TIPE."/{$id}", $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('c.jpg')]])->assertOk()->assertJsonPath('data.jumlah_foto', 3);
        $r = $this->form(self::TIPE."/{$id}", $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('e.jpg')]])->assertStatus(422)->assertJsonValidationErrors('foto');
        $this->assertSame('Foto sudah 3 (maksimal 3). Hapus foto lama dulu sebelum menambah.', $r->json('errors.foto.0'));
        $this->assertSame('VALIDASI_GAGAL', $r->json('code'));
    }

    public function test_hapus_satu_foto_menghapus_berkas_mencatat_log_dan_tipe_boleh_nol_foto(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->json('data.id');
        [$a, $b] = TipeFoto::where('id_tipe', $id)->orderBy('urutan')->get()->all();

        $r = $this->panggil('DELETE', self::TIPE."/{$id}/foto/{$a->id_foto}")->assertOk()->assertJsonPath('data.jumlah_foto', 1);
        $this->assertSame([$b->id_foto], array_column($r->json('data.foto'), 'id'));
        Storage::disk('public')->assertMissing($a->path_foto);
        Storage::disk('public')->assertExists($b->path_foto);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'tipe_kendaraan', 'id_objek' => $id, 'pelaku' => 'admin', 'id_akun' => $this->admin->id_akun, 'aksi' => 'Foto tipe dihapus: Brio']);

        // Foto yang sama tidak bisa dihapus dua kali; foto terakhir boleh dihapus (0 foto)
        $this->panggil('DELETE', self::TIPE."/{$id}/foto/{$a->id_foto}")->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->panggil('DELETE', self::TIPE."/{$id}/foto/{$b->id_foto}")->assertOk()->assertJsonPath('data.jumlah_foto', 0)->assertJsonPath('data.foto', []);
        $this->assertSame(0, TipeFoto::where('id_tipe', $id)->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // Setelah kosong, foto bisa ditambah lagi
        $this->form(self::TIPE."/{$id}", $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('c.jpg')]])->assertOk()->assertJsonPath('data.jumlah_foto', 1);
    }

    public function test_hapus_foto_milik_tipe_lain_atau_tipe_tidak_ada_404_dan_user_biasa_dilarang(): void
    {
        $idA = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('a.jpg')]])->json('data.id');
        $idB = $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Avanza']) + ['foto' => [UploadedFile::fake()->image('b.jpg')]])->json('data.id');
        $fotoB = TipeFoto::where('id_tipe', $idB)->sole();

        // Foto B lewat alamat tipe A: ditolak, berkas dan baris utuh
        $this->panggil('DELETE', self::TIPE."/{$idA}/foto/{$fotoB->id_foto}")->assertNotFound();
        $this->assertSame(1, TipeFoto::where('id_tipe', $idB)->count());
        Storage::disk('public')->assertExists($fotoB->path_foto);

        $this->panggil('DELETE', self::TIPE."/{$idA}/foto/99999")->assertNotFound();
        $this->panggil('DELETE', self::TIPE.'/99999/foto/'.$fotoB->id_foto)->assertNotFound();

        [, $tokenUser] = $this->buatPelanggan();
        $this->panggil('DELETE', self::TIPE."/{$idB}/foto/{$fotoB->id_foto}", [], $tokenUser)->assertForbidden();
        $this->assertSame(1, TipeFoto::where('id_tipe', $idB)->count());
    }

    public function test_teks_nama_dirapikan_tanpa_merusak_singkatan(): void
    {
        $kasus = [
            'tOYOta' => 'Toyota', 'iNNOva rEBORN' => 'Innova Reborn', '  toyota    avanza ' => 'Toyota Avanza', 'TOYOTA' => 'Toyota', 'BRIO' => 'Brio',
            'BMW' => 'BMW', 'MG' => 'MG', 'CR-V' => 'CR-V', 'HR-V' => 'HR-V', 'RAV4' => 'RAV4', 'x-trail' => 'X-Trail', 'grand AVANZA' => 'Grand Avanza',
            'Alphard 2.5' => 'Alphard 2.5', 'pAJERO sPORT' => 'Pajero Sport', 'D-MAX' => 'D-MAX', '' => '',
        ];
        foreach ($kasus as $masuk => $keluar) {
            $this->assertSame($keluar, TeksNama::judul($masuk), "Masukan [{$masuk}]");
        }
    }

    public function test_nama_dan_merk_tipe_dirapikan_saat_tambah_dan_ubah(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe(['nama' => '  iNNOva   rEBORN ', 'merk' => 'tOYOta']))->assertCreated()
            ->assertJsonPath('data.nama', 'Innova Reborn')->assertJsonPath('data.merk', 'Toyota')->json('data.id');
        $this->assertSame(['Innova Reborn', 'Toyota'], [TipeKendaraan::find($id)->nama, TipeKendaraan::find($id)->merk]);

        $this->form(self::TIPE."/{$id}", $this->bodyTipe(['nama' => 'zENIX   hYBRID', 'merk' => 'TOYOTA']))->assertOk()
            ->assertJsonPath('data.nama', 'Zenix Hybrid')->assertJsonPath('data.merk', 'Toyota');
    }

    public function test_kombinasi_nama_dan_merk_unik_tanpa_peka_huruf_dengan_kode_tipe_sudah_ada(): void
    {
        $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Avanza', 'merk' => 'Toyota']))->assertCreated();
        $jumlah = TipeKendaraan::count();

        foreach ([['avanza', 'toyota'], ['AVANZA', 'TOYOTA'], ['  aVANZA ', 'toYOTA'], ['Avanza', 'Toyota']] as [$nama, $merk]) {
            $r = $this->form(self::TIPE, $this->bodyTipe(['nama' => $nama, 'merk' => $merk]))->assertStatus(422)->assertJsonPath('code', 'TIPE_SUDAH_ADA');
            $this->assertSame('Tipe Avanza dengan merk Toyota sudah ada.', $r->json('message'));
            $this->assertSame(['Tipe Avanza dengan merk Toyota sudah ada.'], $r->json('errors.nama'));
        }
        $this->assertSame($jumlah, TipeKendaraan::count());

        // Nama sama dengan merk berbeda, atau merk sama dengan nama berbeda, boleh
        $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Avanza', 'merk' => 'Daihatsu']))->assertCreated();
        $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Veloz', 'merk' => 'Toyota']))->assertCreated();
    }

    public function test_ubah_tipe_mengabaikan_tipe_sendiri_dan_menolak_milik_tipe_lain(): void
    {
        $a = $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Avanza', 'merk' => 'Toyota']))->json('data.id');
        $b = $this->form(self::TIPE, $this->bodyTipe(['nama' => 'Xenia', 'merk' => 'Daihatsu']))->json('data.id');

        // Tanpa mengubah nama, atau hanya mengubah huruf besar kecil milik sendiri: bukan duplikat
        $this->form(self::TIPE."/{$a}", $this->bodyTipe(['nama' => 'Avanza', 'merk' => 'Toyota', 'kursi' => 7]))->assertOk()->assertJsonPath('data.kursi', 7);
        $this->form(self::TIPE."/{$a}", $this->bodyTipe(['nama' => 'aVANZA', 'merk' => 'TOYOTA']))->assertOk()->assertJsonPath('data.nama', 'Avanza');

        // Menjadi kombinasi milik tipe lain: ditolak dan tidak berubah
        $r = $this->form(self::TIPE."/{$a}", $this->bodyTipe(['nama' => 'xenia', 'merk' => 'daihatsu']))->assertStatus(422)->assertJsonPath('code', 'TIPE_SUDAH_ADA');
        $this->assertSame('Tipe Xenia dengan merk Daihatsu sudah ada.', $r->json('message'));
        $this->assertSame(['Avanza', 'Toyota'], [TipeKendaraan::find($a)->nama, TipeKendaraan::find($a)->merk]);
        $this->assertSame(['Xenia', 'Daihatsu'], [TipeKendaraan::find($b)->nama, TipeKendaraan::find($b)->merk]);
    }

    public function test_balapan_yang_lolos_pengecekan_ditolak_indeks_dan_tetap_dijawab_tipe_sudah_ada(): void
    {
        // Seperti dua permintaan bersamaan: tipe sama muncul tepat sesudah pengecekan dan sebelum simpan
        TipeKendaraan::creating(function () {
            DB::table('tipe_kendaraan')->insert(['nama' => 'Balap', 'merk' => 'Uji', 'kursi' => 5, 'bahan_bakar' => 'bensin', 'harga_sopir_per_hari' => 1]);
            TipeKendaraan::flushEventListeners();
        });

        try {
            $this->form(self::TIPE, $this->bodyTipe(['nama' => 'balap', 'merk' => 'uji']))->assertStatus(422)->assertJsonPath('code', 'TIPE_SUDAH_ADA');
        } finally {
            TipeKendaraan::flushEventListeners();
        }
        // Simulasi memasukkan pesaing di transaksi yang sama, jadi ikut dibatalkan; yang penting jawabannya 422 dan tidak ada 500
        $this->assertSame(0, TipeKendaraan::count());
    }

    public function test_tarif_baru_hanya_berlaku_untuk_pesanan_baru(): void
    {
        [$budi, $tokenUser] = $this->buatPelanggan();
        $tipe = $this->buatTipe(2);
        $lama = $this->buatPesanan($budi, $tipe, ['status' => 'dikonfirmasi']); // total 600000 (300000 x 2)

        $this->form(self::TIPE."/{$tipe->id_tipe}", $this->bodyTipe(['harga_lepas_kunci_manual' => 500000]))->assertOk();

        $this->assertSame(600000, (int) $lama->fresh()->total_biaya, 'Pesanan lama menyimpan total_biaya sendiri');
        $baru = $this->panggil('POST', '/api/v1/pemesanan', [
            'id_tipe' => $tipe->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual', 'tgl_mulai' => now()->addDays(20)->toDateString(), 'durasi' => 2,
            'jam_serah' => '09:00', 'metode_ambil' => 'ambil_di_kantor', 'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ], $tokenUser)->assertCreated();
        $this->assertSame(1000000, $baru->json('data.total_biaya'));
    }

    public function test_hapus_tipe_ditolak_bila_punya_unit_atau_pesanan(): void
    {
        $denganUnit = $this->buatTipe(1, 'A');
        $this->panggil('DELETE', self::TIPE."/{$denganUnit->id_tipe}")->assertStatus(409)
            ->assertJsonPath('code', 'TIPE_MASIH_DIPAKAI')->assertJsonPath('message', 'Hapus unit tipe ini lebih dulu');

        // Tanpa unit tetapi punya pesanan
        [$budi] = $this->buatPelanggan();
        $denganPesanan = $this->buatTipe(0, 'B');
        $this->buatPesanan($budi, $denganPesanan);
        $this->panggil('DELETE', self::TIPE."/{$denganPesanan->id_tipe}")->assertStatus(409)->assertJsonPath('code', 'TIPE_MASIH_DIPAKAI');
        $this->assertSame(2, TipeKendaraan::count());
    }

    public function test_hapus_tipe_kosong_hanya_soft_delete_foto_dan_berkas_tetap(): void
    {
        $id = $this->form(self::TIPE, $this->bodyTipe() + ['foto' => [UploadedFile::fake()->image('a.jpg')]])->json('data.id');
        $path = TipeFoto::where('id_tipe', $id)->value('path_foto');
        Storage::disk('public')->assertExists($path);

        $this->panggil('DELETE', self::TIPE."/{$id}")->assertNoContent();

        $this->assertNull(TipeKendaraan::find($id));
        $this->assertNotNull(TipeKendaraan::withTrashed()->find($id)->deleted_at);
        $this->assertSame(1, TipeFoto::count(), 'baris foto tetap');
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'tipe_kendaraan', 'id_objek' => $id, 'aksi' => 'Tipe dihapus ke tempat sampah: Brio']);
        $this->panggil('DELETE', self::TIPE."/{$id}")->assertNotFound();
    }

    // ================================================================ unit

    public function test_tambah_unit_berstatus_tersedia_dengan_detail(): void
    {
        $tipe = $this->buatTipe(0);

        $r = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe))->assertCreated();
        $r->assertJsonPath('data.status', 'tersedia')->assertJsonPath('data.km_terakhir', 45800)->assertJsonPath('data.plat_nomor', 'N 1234 AB')
            ->assertJsonPath('data.tipe', ['id' => $tipe->id_tipe, 'nama' => 'Brio'])->assertJsonPath('data.sedang_disewa', false)
            ->assertJsonPath('data.detail.nama_pemilik', 'PT GantengnyaJatim')->assertJsonPath('data.detail.masa_berlaku_stnk', '2027-03-15');

        $this->assertDatabaseHas('detail_kendaraan', ['id_kendaraan' => $r->json('data.id'), 'no_rangka' => 'MH4001']);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'kendaraan', 'id_objek' => $r->json('data.id'), 'aksi' => 'Unit ditambah: N 1234 AB']);

        // Tanpa km dan detail selain rangka dan mesin (wajib): km 0 dan bawaan milik perusahaan
        $min = $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 2 AB']);
        unset($min['km_terakhir']);
        $min['detail'] = ['no_rangka' => 'MHMIN', 'no_mesin' => 'ENMIN'];
        $this->form(self::UNIT, $min)->assertCreated()->assertJsonPath('data.km_terakhir', 0)->assertJsonPath('data.detail.status_kepemilikan', 'milik_perusahaan');
    }

    public function test_tambah_unit_form_multipart_field_detail_berkurung_siku_dan_kolom_kosong(): void
    {
        $tipe = $this->buatTipe(0);

        // Persis seperti form Swagger: semua nilai berupa teks, detail diratakan detail[...]. PHP mengurai nama berkurung
        // siku menjadi larik (parse_str meniru langkah itu, karena tes memberi parameter langsung)
        parse_str(http_build_query([
            'id_tipe' => (string) $tipe->id_tipe, 'plat_nomor' => 'N 9 ZZ', 'tahun' => '2023', 'warna' => 'Hitam', 'transmisi' => 'matic', 'km_terakhir' => '12000',
            'detail[nama_pemilik]' => 'PT Uji', 'detail[status_kepemilikan]' => 'titipan', 'detail[no_rangka]' => 'MH9', 'detail[no_mesin]' => 'L9',
            'detail[no_bpkb]' => 'BPKB-9', 'detail[masa_berlaku_stnk]' => '2028-01-31', 'detail[catatan]' => 'Form',
        ]), $isi);
        $this->assertSame('PT Uji', $isi['detail']['nama_pemilik']);
        $r = $this->form(self::UNIT, $isi)->assertCreated();
        $r->assertJsonPath('data.tahun', 2023)->assertJsonPath('data.km_terakhir', 12000)->assertJsonPath('data.detail.status_kepemilikan', 'titipan')
            ->assertJsonPath('data.detail.masa_berlaku_stnk', '2028-01-31')->assertJsonPath('data.detail.catatan', 'Form');

        // Kolom opsional dibiarkan kosong (teks kosong): km 0, kepemilikan bawaan, tanggal dan teks kosong
        $r = $this->form(self::UNIT, [
            'id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 8 ZZ', 'tahun' => '2023', 'warna' => 'Putih', 'transmisi' => 'manual', 'km_terakhir' => '',
            'detail' => ['nama_pemilik' => '', 'status_kepemilikan' => '', 'no_rangka' => 'MH8', 'no_mesin' => 'L8', 'no_bpkb' => '', 'masa_berlaku_stnk' => '', 'catatan' => ''],
        ])->assertCreated();
        $r->assertJsonPath('data.km_terakhir', 0)->assertJsonPath('data.detail.status_kepemilikan', 'milik_perusahaan')->assertJsonPath('data.detail.masa_berlaku_stnk', null)
            ->assertJsonPath('data.detail.nama_pemilik', null);

        // Validasi tetap sama pada nilai teks
        $this->form(self::UNIT, ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 7 ZZ', 'tahun' => 'abc', 'warna' => 'Putih', 'transmisi' => 'manual',
            'detail' => ['masa_berlaku_stnk' => '31/01/2028']])->assertStatus(422)->assertJsonValidationErrors(['tahun', 'detail.masa_berlaku_stnk']);
        $this->assertSame(2, Kendaraan::count());
    }

    public function test_no_rangka_mesin_dan_bpkb_harus_unik_tanpa_peka_huruf_dan_spasi_tepi(): void
    {
        $tipe = $this->buatTipe(0);
        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe))->assertCreated(); // MH4001, L15001, BPKB-001

        $pesan = ['no_rangka' => 'No rangka sudah dipakai unit lain.', 'no_mesin' => 'No mesin sudah dipakai unit lain.', 'no_bpkb' => 'No BPKB sudah dipakai unit lain.'];
        $nilai = ['no_rangka' => 'MH4001', 'no_mesin' => 'L15001', 'no_bpkb' => 'BPKB-001'];

        foreach ($nilai as $kolom => $asli) {
            // Huruf kecil dan spasi di tepi dianggap sama; hanya kolom itu yang dilaporkan
            foreach ([strtolower($asli), "  {$asli}  ", ' '.strtolower($asli)] as $varian) {
                $d = $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 5 AB']);
                $d['detail'][$kolom] = $varian;
                $r = $this->form(self::UNIT, $d)->assertStatus(422)->assertJsonValidationErrors("detail.{$kolom}");
                $this->assertSame($pesan[$kolom], $r->json('errors')["detail.{$kolom}"][0]);
                $this->assertSame(['detail.'.$kolom], array_keys($r->json('errors')));
            }
        }
        $this->assertSame(1, Kendaraan::count(), 'Yang bentrok tidak tersimpan');

        // Nilai berbeda satu karakter lolos (format dan panjang tidak dipaksa)
        $d = $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 6 AB']);
        $d['detail'] = ['no_rangka' => 'MH4002', 'no_mesin' => 'x', 'no_bpkb' => 'BPKB-001 '.'B'];
        $this->form(self::UNIT, $d)->assertCreated();
    }

    public function test_hanya_no_bpkb_yang_boleh_kosong_dan_kosong_tidak_dihitung_bentrok(): void
    {
        $tipe = $this->buatTipe(0);
        foreach (['N 1 AB', 'N 2 AB', 'N 3 AB'] as $i => $plat) {
            $this->form(self::UNIT, ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => $plat, 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual',
                'detail' => ['no_rangka' => "MH{$i}", 'no_mesin' => "EN{$i}", 'no_bpkb' => '']])->assertCreated();
        }
        $this->form(self::UNIT, ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 4 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual',
            'detail' => ['no_rangka' => 'MH4', 'no_mesin' => 'EN4']])->assertCreated();
        $this->assertSame(4, Kendaraan::count());
        $this->assertSame(0, DetailKendaraan::whereNotNull('no_bpkb')->count());
    }

    public function test_no_rangka_dan_no_mesin_wajib_saat_tambah_dan_ubah_dengan_pesan_per_kolom(): void
    {
        $tipe = $this->buatTipe(0);
        $dasar = ['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 1 AB', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual'];

        // Tidak dikirim sama sekali, kosong, atau hanya spasi: ditolak dengan pesan per kolom
        foreach ([[], ['detail' => ['no_rangka' => '', 'no_mesin' => '   ']], ['detail' => ['no_bpkb' => 'B1']]] as $detail) {
            $r = $this->form(self::UNIT, $dasar + $detail)->assertStatus(422);
            $this->assertSame(['detail.no_rangka', 'detail.no_mesin'], array_keys($r->json('errors')));
            $this->assertSame('No rangka wajib diisi.', $r->json('errors')['detail.no_rangka'][0]);
            $this->assertSame('No mesin wajib diisi.', $r->json('errors')['detail.no_mesin'][0]);
        }
        // Hanya yang kurang yang dilaporkan
        $r = $this->form(self::UNIT, $dasar + ['detail' => ['no_rangka' => 'MH1']])->assertStatus(422);
        $this->assertSame(['detail.no_mesin'], array_keys($r->json('errors')));
        $this->assertSame(0, Kendaraan::count());

        $id = $this->form(self::UNIT, $dasar + ['detail' => ['no_rangka' => 'MH1', 'no_mesin' => 'EN1']])->assertCreated()->json('data.id');

        // Ubah: JSON tanpa detail, atau dengan rangka dan mesin dikosongkan, ditolak; no_bpkb boleh kosong
        $this->panggil('PUT', self::UNIT."/{$id}", ['warna' => 'Merah'] + $dasar)->assertStatus(422)->assertJsonValidationErrors(['detail.no_rangka', 'detail.no_mesin']);
        $r = $this->panggil('PUT', self::UNIT."/{$id}", $dasar + ['detail' => ['no_rangka' => null, 'no_mesin' => '', 'no_bpkb' => '']])->assertStatus(422);
        $this->assertSame('No rangka wajib diisi.', $r->json('errors')['detail.no_rangka'][0]);
        $this->assertSame('No mesin wajib diisi.', $r->json('errors')['detail.no_mesin'][0]);
        $this->panggil('PUT', self::UNIT."/{$id}", ['warna' => 'Merah', 'detail' => ['no_rangka' => 'MH1', 'no_mesin' => 'EN1', 'no_bpkb' => '']] + $dasar)
            ->assertOk()->assertJsonPath('data.warna', 'Merah')->assertJsonPath('data.detail.no_bpkb', null);
        $this->assertSame('MH1', DetailKendaraan::where('id_kendaraan', $id)->value('no_rangka'), 'Yang ditolak tidak mengubah apa pun');
    }

    public function test_ubah_unit_mengabaikan_diri_sendiri_dan_menolak_nomor_unit_lain(): void
    {
        $tipe = $this->buatTipe(0);
        $a = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe))->json('data.id');
        $b = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 2 AB']))->json('data.id');

        // Nomor milik sendiri (juga dengan huruf beda dan spasi) tidak bentrok
        $d = $this->bodyUnit($tipe->id_tipe, ['warna' => 'Merah']);
        $d['detail']['no_rangka'] = ' mh4001 ';
        $this->panggil('PUT', self::UNIT."/{$a}", $d)->assertOk()->assertJsonPath('data.warna', 'Merah');

        // Memakai nomor unit lain: 422 per kolom
        foreach (['no_rangka' => ' mh4001-N2AB', 'no_mesin' => 'l15001-n2ab', 'no_bpkb' => 'BPKB-001-N2AB '] as $kolom => $milikB) {
            $d = $this->bodyUnit($tipe->id_tipe);
            $d['detail'][$kolom] = $milikB;
            $r = $this->panggil('PUT', self::UNIT."/{$a}", $d)->assertStatus(422);
            $this->assertSame(['detail.'.$kolom], array_keys($r->json('errors')));
        }
        $this->assertSame('MH4001-N2AB', $this->panggil('GET', self::UNIT."/{$b}")->json('data.detail.no_rangka'));
        $this->assertSame('mh4001', strtolower(trim(DetailKendaraan::where('id_kendaraan', $a)->value('no_rangka'))));

        // Rangka dan mesin wajib (tidak boleh dikosongkan); no_bpkb boleh dikosongkan
        $d = $this->bodyUnit($tipe->id_tipe);
        $d['detail']['no_mesin'] = null;
        $this->panggil('PUT', self::UNIT."/{$a}", $d)->assertStatus(422)->assertJsonValidationErrors('detail.no_mesin');
        $d = $this->bodyUnit($tipe->id_tipe);
        $d['detail']['no_bpkb'] = null;
        $this->panggil('PUT', self::UNIT."/{$a}", $d)->assertOk();
    }

    public function test_plat_dirapikan_saat_tambah_dan_ubah(): void
    {
        $tipe = $this->buatTipe(0);
        $kasus = ['n1234ab' => 'N 1234 AB', '  n   1234   ab  ' => 'N 1234 AB', 'kt2525kt' => 'KT 2525 KT', 'N  12' => 'N 12', 'b 9 abc' => 'B 9 ABC', 'AB1234' => 'AB 1234'];

        foreach ($kasus as $masuk => $keluar) {
            Kendaraan::query()->forceDelete(); // hapus keras: soft delete menahan plat dan nomornya di tempat sampah
            $r = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => $masuk]))->assertCreated();
            $this->assertSame($keluar, $r->json('data.plat_nomor'), "Masukan [{$masuk}]");
            $this->assertSame($keluar, Kendaraan::find($r->json('data.id'))->plat_nomor);
        }

        // Ubah juga dirapikan, lewat PUT JSON
        $id = Kendaraan::first()->id_kendaraan;
        $this->panggil('PUT', self::UNIT."/{$id}", $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => ' ab  1234  x ']))->assertOk()->assertJsonPath('data.plat_nomor', 'AB 1234 X');
    }

    public function test_plat_unik_tanpa_peka_huruf_dan_spasi_dan_unit_sendiri_diabaikan(): void
    {
        $tipe = $this->buatTipe(0);
        $a = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 1234 AB']))->json('data.id');
        $b = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 9999 ZZ']))->json('data.id');

        foreach (['N1234AB', 'n 1234 ab', ' N  1234  AB ', 'n1234 AB'] as $varian) {
            $r = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => $varian]))->assertStatus(422)->assertJsonValidationErrors('plat_nomor');
            $this->assertSame('Plat nomor sudah dipakai unit lain.', $r->json('errors.plat_nomor.0'), "Varian [{$varian}]");
        }
        $this->assertSame(2, Kendaraan::count());

        // Ubah: plat sendiri (juga dengan huruf atau spasi lain) tidak bentrok, plat unit lain bentrok
        $this->panggil('PUT', self::UNIT."/{$a}", $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'n1234ab', 'warna' => 'Biru']))->assertOk()
            ->assertJsonPath('data.plat_nomor', 'N 1234 AB')->assertJsonPath('data.warna', 'Biru');
        $this->panggil('PUT', self::UNIT."/{$a}", $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'n9999zz']))->assertStatus(422)
            ->assertJsonPath('errors.plat_nomor.0', 'Plat nomor sudah dipakai unit lain.');
        $this->assertSame('N 9999 ZZ', Kendaraan::find($b)->plat_nomor);
    }

    public function test_format_plat_longgar_menolak_yang_jelas_bukan_plat(): void
    {
        $tipe = $this->buatTipe(0);

        foreach (['1234', 'ABC 12 D', 'N 12345 AB', 'N 1234 ABCD', 'N-1234-AB', 'N AB', '', 'N 12.34 AB'] as $salah) {
            $r = $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => $salah]))->assertStatus(422)->assertJsonValidationErrors('plat_nomor');
            if ($salah !== '') {
                $this->assertStringContainsString('Format plat nomor tidak dikenali', $r->json('errors.plat_nomor.0'), "Masukan [{$salah}]");
            }
        }
        $this->assertSame(0, Kendaraan::count());

        // Tanpa huruf belakang, huruf depan satu atau dua, angka satu sampai empat: lolos
        foreach (['B 1', 'DK 1234', 'AD 12 ZZZ'] as $benar) {
            $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => $benar]))->assertCreated();
        }
    }

    public function test_validasi_unit_dan_plat_unik(): void
    {
        $tipe = $this->buatTipe(0);
        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe))->assertCreated();

        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe))->assertStatus(422)
            ->assertJsonValidationErrors('plat_nomor')->assertJsonPath('errors.plat_nomor.0', 'Plat nomor sudah dipakai unit lain.');
        $this->form(self::UNIT, [])->assertStatus(422)->assertJsonValidationErrors(['id_tipe', 'plat_nomor', 'tahun', 'warna', 'transmisi', 'detail.no_rangka', 'detail.no_mesin']);
        $this->form(self::UNIT, $this->bodyUnit(9999, ['plat_nomor' => 'N 3 AB']))->assertStatus(422)->assertJsonValidationErrors('id_tipe');
        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 3 AB', 'tahun' => 1800]))->assertStatus(422)->assertJsonValidationErrors('tahun');
        $this->form(self::UNIT, $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 3 AB', 'transmisi' => 'cvt']))->assertStatus(422)->assertJsonValidationErrors('transmisi');
        $d = $this->bodyUnit($tipe->id_tipe, ['plat_nomor' => 'N 3 AB']);
        $d['detail']['status_kepemilikan'] = 'leasing'; // tidak ada opsi leasing
        $this->form(self::UNIT, $d)->assertStatus(422)->assertJsonValidationErrors('detail.status_kepemilikan');
        $this->assertSame(1, Kendaraan::count());
    }

    public function test_daftar_unit_dengan_filter_dan_penanda_sedang_disewa(): void
    {
        [$budi] = $this->buatPelanggan();
        $a = $this->buatTipe(2, 'A');
        $b = $this->buatTipe(1, 'B');
        $unitA = $a->kendaraan()->orderBy('id_kendaraan')->get();
        $unitA[1]->update(['status' => 'tidak_tersedia']);
        $this->buatPesanan($budi, $a, ['status' => 'berjalan', 'id_kendaraan' => $unitA[0]->id_kendaraan]);

        $semua = $this->panggil('GET', self::UNIT)->assertOk();
        $this->assertCount(3, $semua->json('data'));
        $this->assertSame([$unitA[0]->id_kendaraan, $unitA[1]->id_kendaraan], $this->panggil('GET', self::UNIT."?id_tipe={$a->id_tipe}")->json('data.*.id'));
        $this->assertSame([$unitA[1]->id_kendaraan], $this->panggil('GET', self::UNIT.'?status=tidak_tersedia')->json('data.*.id'));
        $this->assertSame([true, false], $this->panggil('GET', self::UNIT."?id_tipe={$a->id_tipe}")->json('data.*.sedang_disewa'));
        $this->panggil('GET', self::UNIT.'?status=aneh')->assertStatus(422);

        $this->panggil('GET', self::UNIT."/{$unitA[0]->id_kendaraan}")->assertOk()->assertJsonPath('data.sedang_disewa', true)->assertJsonStructure(['data' => ['detail' => ['nama_pemilik', 'no_bpkb']]]);
        $this->panggil('GET', self::UNIT.'/9999')->assertNotFound();
    }

    public function test_status_tampil_dan_filter_disewa_membedakan_unit_bebas_dari_unit_dipakai(): void
    {
        [$budi] = $this->buatPelanggan();
        $a = $this->buatTipe(4, 'A');
        [$dipakai, $bebas, $servis, $mati] = $a->kendaraan()->orderBy('id_kendaraan')->get()->all();
        $servis->update(['status' => 'dalam_perawatan']);
        $mati->update(['status' => 'tidak_tersedia']);
        $this->buatPesanan($budi, $a, ['status' => 'berjalan', 'id_kendaraan' => $dipakai->id_kendaraan]);
        // Pesanan dikonfirmasi belum memegang unit: tidak membuat unit tampil "disewa"
        $this->buatPesanan($budi, $a, ['status' => 'dikonfirmasi', 'id_kendaraan' => $bebas->id_kendaraan]);

        $tampil = collect($this->panggil('GET', self::UNIT)->assertOk()->json('data'))->pluck('status_tampil', 'id');
        $this->assertSame('disewa', $tampil[$dipakai->id_kendaraan]);
        $this->assertSame('tersedia', $tampil[$bebas->id_kendaraan]);
        $this->assertSame('dalam_perawatan', $tampil[$servis->id_kendaraan]);
        $this->assertSame('tidak_tersedia', $tampil[$mati->id_kendaraan]);

        // Kolom status tidak berubah
        $this->assertSame('tersedia', $dipakai->refresh()->status);
        $this->panggil('GET', self::UNIT."/{$dipakai->id_kendaraan}")->assertOk()
            ->assertJsonPath('data.status', 'tersedia')->assertJsonPath('data.status_tampil', 'disewa');
        $this->panggil('GET', self::UNIT."/{$bebas->id_kendaraan}")->assertJsonPath('data.status_tampil', 'tersedia');

        $this->assertSame([$dipakai->id_kendaraan], $this->panggil('GET', self::UNIT.'?status=disewa')->json('data.*.id'));
        $this->assertSame([$bebas->id_kendaraan], $this->panggil('GET', self::UNIT.'?status=tersedia')->json('data.*.id'));
        $this->assertSame([$servis->id_kendaraan], $this->panggil('GET', self::UNIT.'?status=dalam_perawatan')->json('data.*.id'));
        // Unit dalam servis yang masih memegang pesanan berjalan bukan "disewa": kondisinya bukan tersedia
        $servis->update(['status' => 'dalam_perawatan']);
        $this->buatPesanan($budi, $a, ['status' => 'berjalan', 'id_kendaraan' => $servis->id_kendaraan]);
        $this->assertSame([$dipakai->id_kendaraan], $this->panggil('GET', self::UNIT.'?status=disewa')->json('data.*.id'));
        $this->panggil('GET', self::UNIT.'/'.$servis->id_kendaraan)->assertJsonPath('data.status_tampil', 'dalam_perawatan');
    }

    public function test_ubah_unit_plat_milik_sendiri_boleh_dan_tipe_hanya_bila_belum_dipakai_pesanan(): void
    {
        [$budi] = $this->buatPelanggan();
        $a = $this->buatTipe(0, 'A');
        $b = $this->buatTipe(0, 'B');
        $unit = $this->form(self::UNIT, $this->bodyUnit($a->id_tipe))->json('data.id');
        $this->form(self::UNIT, $this->bodyUnit($a->id_tipe, ['plat_nomor' => 'N 2 AB']));

        // Plat sendiri tidak dianggap duplikat; detail berubah, km tidak dikirim tetap
        $d = $this->bodyUnit($a->id_tipe, ['warna' => 'Merah']);
        unset($d['km_terakhir']);
        $d['detail']['catatan'] = 'Servis rutin';
        $this->panggil('PUT', self::UNIT."/{$unit}", $d)->assertOk()->assertJsonPath('data.warna', 'Merah')->assertJsonPath('data.km_terakhir', 45800)->assertJsonPath('data.detail.catatan', 'Servis rutin');
        // Plat milik unit lain: ditolak
        $this->panggil('PUT', self::UNIT."/{$unit}", $this->bodyUnit($a->id_tipe, ['plat_nomor' => 'N 2 AB']))->assertStatus(422)->assertJsonValidationErrors('plat_nomor');

        // Pindah tipe boleh selama belum pernah dipakai pesanan
        $this->panggil('PUT', self::UNIT."/{$unit}", $this->bodyUnit($b->id_tipe))->assertOk()->assertJsonPath('data.id_tipe', $b->id_tipe);
        $this->buatPesanan($budi, $b, ['status' => 'selesai', 'id_kendaraan' => $unit]);
        $this->panggil('PUT', self::UNIT."/{$unit}", $this->bodyUnit($a->id_tipe))->assertStatus(409)->assertJsonPath('code', 'UNIT_PUNYA_PEMESANAN');
        $this->panggil('PUT', self::UNIT.'/9999', $this->bodyUnit($a->id_tipe, ['plat_nomor' => 'N 77 ZZ']))->assertNotFound();
    }

    public function test_hapus_unit_berriwayat_diizinkan_tetapi_ditolak_bila_sedang_disewa_atau_diservis(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(5);
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();

        // Riwayat pesanan atau servis tidak lagi menghalangi
        $this->buatPesanan($budi, $t, ['status' => 'selesai', 'id_kendaraan' => $unit[0]->id_kendaraan]);
        $this->panggil('DELETE', self::UNIT."/{$unit[0]->id_kendaraan}")->assertNoContent();
        Perawatan::create(['id_kendaraan' => $unit[1]->id_kendaraan, 'jenis_servis' => 'Servis', 'tingkat_servis' => 'ringan', 'tgl_perawatan' => now()->toDateString(), 'status' => 'selesai']);
        $this->panggil('DELETE', self::UNIT."/{$unit[1]->id_kendaraan}")->assertNoContent();

        // Sedang disewa (pesanan berjalan) dan dalam servis tetap ditolak
        $this->buatPesanan($budi, $t, ['status' => 'berjalan', 'id_kendaraan' => $unit[2]->id_kendaraan]);
        $this->panggil('DELETE', self::UNIT."/{$unit[2]->id_kendaraan}")->assertStatus(409)->assertJsonPath('code', 'UNIT_SEDANG_DISEWA');
        $unit[3]->update(['status' => 'dalam_perawatan']);
        $this->panggil('DELETE', self::UNIT."/{$unit[3]->id_kendaraan}")->assertStatus(409)->assertJsonPath('code', 'UNIT_DALAM_SERVIS');
        $this->assertNotNull(Kendaraan::find($unit[2]->id_kendaraan));
        $this->assertNotNull(Kendaraan::find($unit[3]->id_kendaraan));

        $this->panggil('DELETE', self::UNIT."/{$unit[4]->id_kendaraan}")->assertNoContent();
        $this->assertNull(Kendaraan::find($unit[4]->id_kendaraan));
        $this->assertNotNull(Kendaraan::withTrashed()->find($unit[4]->id_kendaraan)->deleted_at);
        $this->panggil('DELETE', self::UNIT."/{$unit[4]->id_kendaraan}")->assertNotFound();
    }

    public function test_hapus_unit_yang_membuat_pesanan_kekurangan_unit_memberi_peringatan(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);

        $this->panggil('DELETE', self::UNIT."/{$unit->id_kendaraan}")->assertStatus(409)->assertJsonPath('code', 'PESANAN_TERDAMPAK');
        $this->assertNotNull(Kendaraan::find($unit->id_kendaraan));

        $this->panggil('DELETE', self::UNIT."/{$unit->id_kendaraan}?konfirmasi=true")->assertNoContent();
        $this->assertNull(Kendaraan::find($unit->id_kendaraan));
    }

    // ---------------------------------------------------------------- status unit

    public function test_tandai_tidak_tersedia_dan_tersedia_kembali_memengaruhi_katalog(): void
    {
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $mulai = now()->addDays(3)->toDateString();
        $katalog = function () use ($mulai) {
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();

            return count($this->getJson("/api/v1/katalog/tipe?tgl_mulai={$mulai}&durasi=1")->json('data'));
        };

        $this->assertSame(1, $katalog());
        $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertOk()->assertJsonPath('data.status', 'tidak_tersedia');
        $this->assertSame('tidak_tersedia', $unit->fresh()->status);
        $this->assertSame(0, $katalog());
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'kendaraan', 'id_objek' => $unit->id_kendaraan, 'id_akun' => $this->admin->id_akun, 'aksi' => "Unit {$unit->plat_nomor} ditandai tidak_tersedia"]);

        $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'tersedia'])->assertOk()->assertJsonPath('data.status', 'tersedia');
        $this->assertSame(1, $katalog());

        // Status yang sama: tidak berubah dan tidak menambah log
        $log = \App\Models\LogAktivitas::count();
        $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'tersedia'])->assertOk();
        $this->assertSame($log, \App\Models\LogAktivitas::count());

        $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'dalam_perawatan'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->panggil('PATCH', self::UNIT.'/9999/status', ['status' => 'tersedia'])->assertNotFound();
    }

    public function test_status_unit_ditolak_saat_disewa_atau_dalam_servis(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(2);
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get();
        $this->buatPesanan($budi, $t, ['status' => 'berjalan', 'id_kendaraan' => $unit[0]->id_kendaraan]);
        $unit[1]->update(['status' => 'dalam_perawatan']);

        $this->panggil('PATCH', self::UNIT."/{$unit[0]->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertStatus(409)->assertJsonPath('code', 'UNIT_SEDANG_DISEWA');
        $this->panggil('PATCH', self::UNIT."/{$unit[1]->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertStatus(409)->assertJsonPath('code', 'UNIT_DALAM_SERVIS');
        $this->panggil('PATCH', self::UNIT."/{$unit[1]->id_kendaraan}/status", ['status' => 'tersedia', 'konfirmasi' => true])->assertStatus(409)->assertJsonPath('code', 'UNIT_DALAM_SERVIS');
        $this->assertSame(['tersedia', 'dalam_perawatan'], [$unit[0]->fresh()->status, $unit[1]->fresh()->status]);
    }

    public function test_pesanan_terdampak_memberi_peringatan_dan_bisa_dilanjutkan_dengan_konfirmasi(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $p = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);

        $r = $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertStatus(409);
        $r->assertJsonPath('code', 'PESANAN_TERDAMPAK')->assertJsonPath('message', 'Ada pesanan dikonfirmasi yang membutuhkan unit tipe ini')
            ->assertJsonPath('pesanan_terdampak', [[
                'id' => $p->id_pemesanan, 'nomor' => $p->nomor, 'nama' => 'Budi',
                'tgl_mulai' => $p->tgl_mulai->toDateString(), 'tgl_kembali' => $p->tgl_kembali->toDateString(),
            ]]);
        $this->assertSame('tersedia', $unit->fresh()->status, 'Peringatan tidak mengubah apa pun');

        // konfirmasi=false tetap peringatan; konfirmasi=true melanjutkan (tanggung jawab admin) dan tercatat
        $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'tidak_tersedia', 'konfirmasi' => false])->assertStatus(409);
        $this->panggil('PATCH', self::UNIT."/{$unit->id_kendaraan}/status", ['status' => 'tidak_tersedia', 'konfirmasi' => true])->assertOk();
        $this->assertSame('tidak_tersedia', $unit->fresh()->status);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $unit->id_kendaraan, 'aksi' => "Unit {$unit->plat_nomor} ditandai tidak_tersedia (dengan konfirmasi pesanan terdampak)"]);
    }

    public function test_tidak_terdampak_bila_kapasitas_cukup_atau_tanggal_tidak_beririsan(): void
    {
        [$budi] = $this->buatPelanggan();

        // Dua unit, satu pesanan: menonaktifkan satu unit masih cukup
        $t = $this->buatTipe(2, 'A');
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $this->panggil('PATCH', self::UNIT."/{$t->kendaraan()->first()->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertOk();

        // Dua unit, dua pesanan pada tanggal berbeda: tetap cukup
        $u = $this->buatTipe(2, 'B');
        $this->buatPesanan($budi, $u, ['status' => 'dikonfirmasi', 'tgl_mulai' => now()->addDays(3)->toDateString(), 'durasi' => 2]);
        $this->buatPesanan($budi, $u, ['status' => 'dikonfirmasi', 'tgl_mulai' => now()->addDays(10)->toDateString(), 'durasi' => 2]);
        $this->panggil('PATCH', self::UNIT."/{$u->kendaraan()->first()->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertOk();

        // Dua unit, dua pesanan beririsan: menonaktifkan satu unit memengaruhi keduanya
        $v = $this->buatTipe(2, 'C');
        $a = $this->buatPesanan($budi, $v, ['status' => 'dikonfirmasi']);
        $b = $this->buatPesanan($budi, $v, ['status' => 'menunggu_pembayaran', 'tgl_mulai' => now()->addDays(6)->toDateString()]);
        $r = $this->panggil('PATCH', self::UNIT."/{$v->kendaraan()->first()->id_kendaraan}/status", ['status' => 'tidak_tersedia'])->assertStatus(409);
        $this->assertEqualsCanonicalizing([$a->id_pemesanan, $b->id_pemesanan], $r->json('pesanan_terdampak.*.id'));
    }
}
