<?php

namespace Tests\Feature;

use App\Http\Requests\Api\PengembalianRequest;
use App\Models\BiayaTambahan;
use App\Models\Jaminan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\Pengembalian;
use App\Models\PengembalianFoto;
use App\Models\PengembalianKondisi;
use App\Models\Perawatan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/**
 * POST .../pengembalian/preview dan .../pengembalian. Pesanan uji: 3 hari, tarif 300000 per hari (sewa 900000),
 * kembali rencana HARI INI; unit diserahkan dengan odometer awal 45800 (lihat BuatDataPemesanan::buatPesananBerjalan).
 */
class AdminPengembalianTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/admin/pemesanan';

    private string $token;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->token] = $this->buatAdmin();
    }

    private function panggil(string $url, array $isi, string $metode = 'POST')
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->token)->json($metode, $url, $isi);
    }

    /**
     * POST multipart dengan field rata. Body ditulis dengan bentuk daftar (kondisi[] dan biaya_tambahan[]) lalu diratakan:
     * kondisi[b], biaya[b], keterangan[b], biaya_tambahan[jenis], keterangan_tambahan[jenis]. Foto per bagian dibuat otomatis
     * untuk tiap bagian yang tidak baik, kecuali $fotoOtomatis = false. $foto menambah atau menimpa foto per bagian.
     */
    private function kirim(string $url, array $body, bool $fotoOtomatis = true, array $foto = [], ?string $token = null)
    {
        Storage::fake('local');
        $params = $body;
        $files = [];

        if (isset($body['kondisi'])) {
            unset($params['kondisi']);
            foreach ($body['kondisi'] as $k) {
                $b = $k['bagian'];
                $params['kondisi'][$b] = $k['kondisi'];
                if (isset($k['biaya'])) {
                    $params['biaya'][$b] = $k['biaya'];
                }
                if (! empty($k['keterangan'])) {
                    $params['keterangan'][$b] = $k['keterangan'];
                }
                if ($fotoOtomatis && ($k['kondisi'] ?? 'baik') !== 'baik') {
                    $files['foto'][$b] = UploadedFile::fake()->image("{$b}.jpg");
                }
            }
        }
        if (isset($body['biaya_tambahan'])) {
            unset($params['biaya_tambahan']);
            foreach ($body['biaya_tambahan'] as $t) {
                $params['biaya_tambahan'][$t['jenis']] = $t['nominal'];
                if (! empty($t['keterangan'])) {
                    $params['keterangan_tambahan'][$t['jenis']] = $t['keterangan'];
                }
            }
        }
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false'; // form multipart mengirim boolean sebagai teks
            }
        }
        foreach ($foto as $bagian => $berkas) {
            $files['foto'][$bagian] = $berkas;
        }

        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars([
            'Accept' => 'application/json', 'Authorization' => 'Bearer '.($token ?? $this->token), 'Content-Type' => 'multipart/form-data',
        ]);

        return $this->call('POST', $url, $params, [], $files, $server);
    }

    /** Seperti kirim() tetapi kolom foto[<bagian>] dikirim sebagai teks kosong, seperti form Swagger yang tak diisi. */
    private function kirimDenganFotoKosong(string $url, array $body, array $fotoKosong)
    {
        $params = $body;
        unset($params['kondisi']);
        foreach ($body['kondisi'] as $k) {
            $params['kondisi'][$k['bagian']] = $k['kondisi'];
            if (isset($k['biaya'])) {
                $params['biaya'][$k['bagian']] = $k['biaya'];
            }
        }
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            }
        }
        $params['foto'] = $fotoKosong;

        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'multipart/form-data']);

        return $this->call('POST', $url, $params, [], [], $server);
    }

    private function berjalan(array $ubah = []): Pemesanan
    {
        [$p] = $this->buatPelanggan('p'.uniqid().'@mail.test');

        return $this->buatPesananBerjalan($p, $this->buatTipe(1, 'T'.uniqid()), $ubah);
    }

    private function semuaBaik(): array
    {
        return collect(['depan', 'belakang', 'sisi_kiri', 'sisi_kanan', 'atap', 'kaca_lampu', 'ban_velg', 'interior'])
            ->map(fn ($b) => ['bagian' => $b, 'kondisi' => 'baik', 'biaya' => 0, 'keterangan' => ''])->all();
    }

    /** Sama dengan contoh di spek: terlambat 1 hari, BBM kurang 30000, kerusakan 200000. */
    private function bodyContohSpek(array $ubah = []): array
    {
        $kondisi = $this->semuaBaik();
        $kondisi[0] = ['bagian' => 'depan', 'kondisi' => 'penyok', 'biaya' => 200000, 'keterangan' => 'Bemper penyok'];

        return $ubah + [
            'tgl_kembali' => now()->addDay()->toDateString(), 'jam_kembali' => '18:30',
            'odometer_akhir' => 46100, 'bbm_akhir' => 'tiga_perempat', 'biaya_kekurangan_bbm' => 30000, 'kebersihan' => 'bersih',
            'kondisi' => $kondisi, 'disetujui_penyewa' => true, 'status_pembayaran_tagihan' => 'belum_dibayar',
        ];
    }

    private function bodyTanpaTagihan(array $ubah = []): array
    {
        return $ubah + [
            'tgl_kembali' => now()->toDateString(), 'jam_kembali' => '17:00', 'odometer_akhir' => 46000, 'bbm_akhir' => 'penuh',
            'biaya_kekurangan_bbm' => 0, 'kebersihan' => 'bersih', 'kondisi' => $this->semuaBaik(), 'disetujui_penyewa' => true,
        ];
    }

    // ---- preview ----

    public function test_preview_menghitung_ringkasan_persis_seperti_contoh_spek_tanpa_menyimpan(): void
    {
        $p = $this->berjalan();
        $body = $this->bodyContohSpek();
        unset($body['disetujui_penyewa']); // preview tidak menuntut persetujuan maupun foto

        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian/preview", $body)->assertOk()
            ->assertJsonPath('data.ringkasan', [
                'biaya_sewa' => 900000, 'hari_terlambat' => 1, 'denda_keterlambatan' => 300000, 'kekurangan_bbm' => 30000,
                'biaya_kerusakan' => 200000, 'biaya_lain' => 0, 'tagihan_tambahan' => 530000, 'total_akhir' => 1430000,
            ]);

        $this->assertSame('berjalan', $p->fresh()->status);
        $this->assertSame(0, Pengembalian::count() + Pembayaran::where('jenis', 'tambahan')->count());
        $this->assertDatabaseMissing('log_aktivitas', ['aksi' => 'Pesanan diselesaikan (pengembalian dan pemeriksaan)']);
    }

    public function test_preview_biaya_lain_tepat_waktu_dan_pengembalian_lebih_awal(): void
    {
        $p = $this->berjalan();

        // Tepat tanggal kembali (jam berapa pun): tidak terlambat
        $r = $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian/preview", $this->bodyTanpaTagihan(['jam_kembali' => '23:59', 'biaya_tambahan' => [
            ['jenis' => 'cuci', 'keterangan' => 'Sangat kotor', 'nominal' => 50000], ['jenis' => 'biaya_antar', 'nominal' => 40000],
        ]]))->assertOk();
        $r->assertJsonPath('data.ringkasan.hari_terlambat', 0)->assertJsonPath('data.ringkasan.biaya_lain', 90000)
            ->assertJsonPath('data.ringkasan.tagihan_tambahan', 90000)->assertJsonPath('data.ringkasan.total_akhir', 990000);

        // Kembali lebih awal: tidak ada denda negatif
        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian/preview", $this->bodyTanpaTagihan(['tgl_kembali' => now()->subDay()->toDateString()]))
            ->assertOk()->assertJsonPath('data.ringkasan.hari_terlambat', 0)->assertJsonPath('data.ringkasan.denda_keterlambatan', 0);

        // Terlambat tiga hari
        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian/preview", $this->bodyTanpaTagihan(['tgl_kembali' => now()->addDays(3)->toDateString()]))
            ->assertOk()->assertJsonPath('data.ringkasan.hari_terlambat', 3)->assertJsonPath('data.ringkasan.denda_keterlambatan', 900000);
    }

    // ---- pengembalian ----

    public function test_selesaikan_menyimpan_pengembalian_tagihan_unit_dan_log(): void
    {
        $p = $this->berjalan();
        $unit = $p->kendaraan;

        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyContohSpek(['catatan_admin' => 'Bemper dicek']))->assertOk()
            ->assertJsonPath('data', [
                'status' => 'selesai',
                'ringkasan' => ['biaya_sewa' => 900000, 'hari_terlambat' => 1, 'denda_keterlambatan' => 300000, 'kekurangan_bbm' => 30000,
                    'biaya_kerusakan' => 200000, 'biaya_lain' => 0, 'tagihan_tambahan' => 530000, 'total_akhir' => 1430000],
                'tagihan_status' => 'belum', 'jaminan_status' => 'ditahan', 'unit_status' => 'tersedia',
            ]);

        $this->assertSame('selesai', $p->fresh()->status);
        $pg = Pengembalian::where('id_pemesanan', $p->id_pemesanan)->sole();
        $this->assertSame([300000, 1, 300000, 46100, 'tiga_perempat', 30000, 'bersih', true, 'Bemper dicek'],
            [$pg->tarif_denda_per_hari, $pg->hari_terlambat, $pg->denda_keterlambatan, $pg->odometer_akhir, $pg->bbm_akhir,
                $pg->biaya_kekurangan_bbm, $pg->kebersihan, $pg->disetujui_penyewa, $pg->catatan_admin]);
        $this->assertSame(8, PengembalianKondisi::where('id_pengembalian', $pg->id_pengembalian)->count());
        $this->assertSame(200000, (int) PengembalianKondisi::where('bagian', 'depan')->value('biaya'));

        // Tagihan tambahan tercatat sebagai pembayaran berjenis tambahan, belum dibayar
        $tagihan = Pembayaran::where('id_pemesanan', $p->id_pemesanan)->where('jenis', 'tambahan')->sole();
        $this->assertSame([530000, 'menunggu', null], [$tagihan->jumlah, $tagihan->status, $tagihan->tgl_bayar]);

        // Unit: kilometer diperbarui dan tersedia lagi; jaminan ditahan
        $unit->refresh();
        $this->assertSame(['tersedia', 46100], [$unit->status, $unit->km_terakhir]);
        $this->assertSame(0, Jaminan::where('dikembalikan', true)->count());
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'pelaku' => 'admin', 'aksi' => 'Pesanan diselesaikan (pengembalian dan pemeriksaan)']);

        // Muncul di detail admin dan di detail user
        $detail = $this->panggil(self::API."/{$p->id_pemesanan}", [], 'GET')->assertOk();
        $detail->assertJsonPath('data.pengembalian.denda_keterlambatan', 300000)->assertJsonPath('data.tagihan_tambahan.jumlah', 530000);
        $this->assertCount(8, $detail->json('data.pengembalian.kondisi'));
    }

    public function test_tagihan_tunai_atau_transfer_langsung_lunas(): void
    {
        foreach (['tunai', 'transfer'] as $cara) {
            $p = $this->berjalan();
            $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyContohSpek(['status_pembayaran_tagihan' => $cara]))->assertOk()
                ->assertJsonPath('data.tagihan_status', 'lunas')->assertJsonPath('data.jaminan_status', 'dikembalikan'); // tagihan langsung lunas

            $tagihan = Pembayaran::where('id_pemesanan', $p->id_pemesanan)->where('jenis', 'tambahan')->sole();
            $this->assertSame(['lunas', $cara], [$tagihan->status, $tagihan->metode]);
            $this->assertNotNull($tagihan->tgl_bayar);
            $this->assertSame(4, Jaminan::where('id_pemesanan', $p->id_pemesanan)->where('dikembalikan', true)->count());
        }
    }

    public function test_tanpa_tagihan_jaminan_otomatis_dikembalikan(): void
    {
        $a = $this->berjalan();
        $this->kirim(self::API."/{$a->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan())->assertOk()
            ->assertJsonPath('data.tagihan_status', null)->assertJsonPath('data.jaminan_status', 'dikembalikan')
            ->assertJsonPath('data.ringkasan.tagihan_tambahan', 0);
        $this->assertSame(0, Pembayaran::where('jenis', 'tambahan')->count(), 'Tagihan nol tidak membuat pembayaran');
        $this->assertSame(4, Jaminan::where('id_pemesanan', $a->id_pemesanan)->where('dikembalikan', true)->count());
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $a->id_pemesanan, 'aksi' => 'Jaminan dikembalikan otomatis (saat pengembalian)']);

        // Field kembalikan_jaminan sudah dihapus: yang terkirim diabaikan, tidak memengaruhi hasil
        $b = $this->berjalan();
        $this->kirim(self::API."/{$b->id_pemesanan}/pengembalian", $this->bodyContohSpek(['kembalikan_jaminan' => 'true']))->assertOk()
            ->assertJsonPath('data.jaminan_status', 'ditahan');
    }

    public function test_tagihan_belum_lunas_menahan_jaminan_lalu_dikembalikan_otomatis_saat_dilunasi(): void
    {
        $p = $this->berjalan();

        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyContohSpek())->assertOk()
            ->assertJsonPath('data.tagihan_status', 'belum')->assertJsonPath('data.jaminan_status', 'ditahan');
        $this->assertSame(0, Jaminan::where('id_pemesanan', $p->id_pemesanan)->where('dikembalikan', true)->count());
        $this->panggil(self::API."/{$p->id_pemesanan}", [], 'GET')->assertJsonPath('data.jaminan.status', 'ditahan');

        // Dilunasi admin: jaminan ikut dikembalikan otomatis
        $this->panggil(self::API."/{$p->id_pemesanan}/tagihan/lunas", ['metode' => 'tunai'])->assertOk()
            ->assertJsonPath('data', ['tagihan_status' => 'lunas', 'jaminan_status' => 'dikembalikan']);
        $this->assertSame(4, Jaminan::where('id_pemesanan', $p->id_pemesanan)->where('dikembalikan', true)->count());
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'aksi' => 'Jaminan dikembalikan otomatis (tagihan lunas)']);
        $this->panggil(self::API."/{$p->id_pemesanan}", [], 'GET')->assertJsonPath('data.jaminan.status', 'dikembalikan')->assertJsonPath('data.aksi.bisa_kembalikan_jaminan', false);
    }

    public function test_dengan_sopir_tidak_punya_jaminan_walau_tagihan_lunas_atau_belum(): void
    {
        foreach (['tunai', 'belum_dibayar'] as $cara) {
            $p = $this->berjalan(['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null, 'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun']);
            $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyContohSpek(['status_pembayaran_tagihan' => $cara]))->assertOk()
                ->assertJsonPath('data.jaminan_status', null);
            if ($cara === 'belum_dibayar') {
                $this->panggil(self::API."/{$p->id_pemesanan}/tagihan/lunas", ['metode' => 'tunai'])->assertOk()->assertJsonPath('data.jaminan_status', null);
            }
            $this->assertSame(0, Jaminan::where('id_pemesanan', $p->id_pemesanan)->count());
        }
    }

    public function test_unit_perlu_servis_masuk_perawatan_dengan_asal_pesanan(): void
    {
        $p = $this->berjalan();
        $unit = $p->kendaraan;

        // servis.tingkat dan servis.keperluan wajib bila unit_perlu_servis
        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan(['unit_perlu_servis' => true]))
            ->assertStatus(422)->assertJsonValidationErrors(['servis.tingkat', 'servis.keperluan']);

        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan([
            'unit_perlu_servis' => true, 'servis' => ['tingkat' => 'sedang', 'keperluan' => 'Perbaikan bemper'],
        ]))->assertOk()->assertJsonPath('data.unit_status', 'dalam_perawatan');

        $this->assertSame('dalam_perawatan', $unit->fresh()->status);
        $perawatan = Perawatan::where('id_kendaraan', $unit->id_kendaraan)->sole();
        $this->assertSame([$p->id_pemesanan, 'Perbaikan bemper', 'sedang', 'berlangsung', 46000],
            [$perawatan->id_pemesanan_asal, $perawatan->jenis_servis, $perawatan->tingkat_servis, $perawatan->status, $perawatan->km_saat_ini]);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'kendaraan', 'id_objek' => $unit->id_kendaraan, 'pelaku' => 'admin']);
    }

    public function test_validasi_pengembalian(): void
    {
        $p = $this->berjalan();
        $url = self::API."/{$p->id_pemesanan}/pengembalian";
        $rusak = $this->semuaBaik();
        $rusak[2] = ['bagian' => 'sisi_kiri', 'kondisi' => 'lecet', 'keterangan' => 'Baret'];

        // Odometer lebih kecil dari odometer awal (45800)
        $this->kirim($url, $this->bodyTanpaTagihan(['odometer_akhir' => 45000]))->assertStatus(422)->assertJsonValidationErrors('odometer_akhir');
        // Kondisi bukan baik: biaya dan foto bagian itu wajib
        $r = $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => $rusak]), false)->assertStatus(422);
        $this->assertSame(['biaya.sisi_kiri', 'foto.sisi_kiri'], array_keys($r->json('errors')));
        $this->assertSame('Biaya perbaikan bagian sisi kiri wajib diisi karena kondisinya tidak baik', $r->json('errors')['biaya.sisi_kiri'][0]);
        $this->assertSame('Foto bagian sisi kiri wajib diunggah karena kondisinya tidak baik', $r->json('errors')['foto.sisi_kiri'][0]);
        // Delapan bagian wajib semuanya: bagian yang hilang dilaporkan per bagian
        $r = $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => array_slice($this->semuaBaik(), 0, 7)]))->assertStatus(422);
        $this->assertSame(['kondisi.interior'], array_keys($r->json('errors')));
        $this->assertSame('Kondisi bagian interior wajib diisi', $r->json('errors')['kondisi.interior'][0]);
        $salah = $this->semuaBaik();
        $salah[1]['kondisi'] = 'hancur';
        $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => $salah]), false)->assertStatus(422)->assertJsonValidationErrors('kondisi.belakang');
        // Persetujuan penyewa, tanggal sebelum mulai sewa, dan kolom lepas kunci
        $this->kirim($url, $this->bodyTanpaTagihan(['disetujui_penyewa' => false]))->assertStatus(422)->assertJsonValidationErrors('disetujui_penyewa');
        $this->kirim($url, $this->bodyTanpaTagihan(['tgl_kembali' => now()->subDays(5)->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('tgl_kembali');
        $this->kirim($url, [])->assertStatus(422)->assertJsonValidationErrors(['tgl_kembali', 'jam_kembali', 'kondisi', 'odometer_akhir', 'bbm_akhir', 'biaya_kekurangan_bbm', 'kebersihan', 'disetujui_penyewa']);
        $this->kirim($url, $this->bodyTanpaTagihan(['biaya_tambahan' => [['jenis' => 'cuci', 'nominal' => -1]]]))->assertStatus(422)
            ->assertJsonValidationErrors('biaya_tambahan.cuci');

        $this->assertSame('berjalan', $p->fresh()->status);
        $this->assertSame(0, Pengembalian::count());
    }

    // ---- format rata, foto per bagian, multipart saja ----

    public function test_hanya_multipart_json_ditolak_415_untuk_pengembalian_dan_preview(): void
    {
        $p = $this->berjalan();

        foreach (['/pengembalian', '/pengembalian/preview'] as $akhir) {
            $this->panggil(self::API."/{$p->id_pemesanan}{$akhir}", $this->bodyTanpaTagihan())
                ->assertStatus(415)->assertJsonPath('code', 'FORMAT_TIDAK_DIDUKUNG');
        }
        $this->assertSame('berjalan', $p->fresh()->status);
        $this->assertSame(0, Pengembalian::count());
    }

    public function test_form_rata_foto_per_bagian_biaya_tambahan_per_jenis_dan_boolean_teks(): void
    {
        Storage::fake('local');
        $p = $this->berjalan();
        $this->app['auth']->forgetGuards();

        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'multipart/form-data']);
        $r = $this->call('POST', self::API."/{$p->id_pemesanan}/pengembalian", [
            'tgl_kembali' => now()->toDateString(), 'jam_kembali' => '17:00', 'odometer_akhir' => '46000', 'bbm_akhir' => 'penuh',
            'biaya_kekurangan_bbm' => '0', 'kebersihan' => 'bersih', 'disetujui_penyewa' => 'true', 'unit_perlu_servis' => 'false', 'kembalikan_jaminan' => 'false',
            'kondisi' => ['depan' => 'lecet', 'belakang' => 'baik', 'sisi_kiri' => 'penyok', 'sisi_kanan' => 'baik', 'atap' => 'baik', 'kaca_lampu' => 'baik', 'ban_velg' => 'baik', 'interior' => 'baik'],
            'biaya' => ['depan' => '75000', 'sisi_kiri' => '120000', 'belakang' => '999999'], // biaya bagian baik diabaikan
            'keterangan' => ['depan' => 'Baret', 'sisi_kiri' => 'Penyok pintu'],
            'biaya_tambahan' => ['cuci' => '25000', 'biaya_antar' => '15000', 'kelengkapan_hilang' => '', 'lainnya' => '0'],
            'keterangan_tambahan' => ['cuci' => 'Sangat kotor'],
        ], [], ['foto' => ['depan' => UploadedFile::fake()->image('d.jpg'), 'sisi_kiri' => UploadedFile::fake()->image('k.png')]], $server)->assertOk();

        $r->assertJsonPath('data.ringkasan.biaya_kerusakan', 195000)->assertJsonPath('data.ringkasan.biaya_lain', 40000)
            ->assertJsonPath('data.ringkasan.tagihan_tambahan', 235000)->assertJsonPath('data.unit_status', 'tersedia');

        // Kondisi tersimpan per bagian; biaya bagian baik 0
        $kondisi = PengembalianKondisi::pluck('biaya', 'bagian')->map(fn ($b) => (int) $b)->all();
        $this->assertSame(75000, $kondisi['depan']);
        $this->assertSame(120000, $kondisi['sisi_kiri']);
        $this->assertSame(0, $kondisi['belakang']);
        $this->assertSame('Penyok pintu', PengembalianKondisi::where('bagian', 'sisi_kiri')->value('keterangan'));

        // Biaya tambahan: hanya jenis yang bernominal
        $this->assertEqualsCanonicalizing(['cuci', 'biaya_antar'], BiayaTambahan::pluck('jenis')->all());
        $this->assertSame('Sangat kotor', BiayaTambahan::where('jenis', 'cuci')->value('keterangan'));

        // Foto per bagian di disk privat, bisa dikenali bagian mana yang rusak
        $foto = PengembalianFoto::pluck('path_foto', 'bagian')->all();
        $this->assertEqualsCanonicalizing(['depan', 'sisi_kiri'], array_keys($foto));
        foreach ($foto as $path) {
            Storage::disk('local')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }
        $this->assertSame(0, Perawatan::count());

        $kondisiDetail = collect($this->panggil(self::API."/{$p->id_pemesanan}", [], 'GET')->json('data.pengembalian.kondisi'))->pluck('ada_foto', 'bagian')->all();
        $this->assertSame(['depan' => true, 'belakang' => false, 'sisi_kiri' => true], array_intersect_key($kondisiDetail, array_flip(['depan', 'belakang', 'sisi_kiri'])));
    }

    public function test_foto_wajib_per_bagian_yang_tidak_baik_dan_foto_bagian_lain_tidak_menggantikan(): void
    {
        $p = $this->berjalan();
        $kondisi = $this->semuaBaik();
        $kondisi[0] = ['bagian' => 'depan', 'kondisi' => 'lecet', 'biaya' => 50000];
        $kondisi[3] = ['bagian' => 'sisi_kanan', 'kondisi' => 'rusak', 'biaya' => 300000];
        $url = self::API."/{$p->id_pemesanan}/pengembalian";

        // Hanya depan yang difoto: sisi_kanan tetap dituntut, depan tidak
        $r = $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => $kondisi]), false, ['depan' => UploadedFile::fake()->image('d.jpg')])->assertStatus(422);
        $this->assertSame(['foto.sisi_kanan'], array_keys($r->json('errors')));

        // Foto di bagian yang baik tidak menggantikan foto bagian yang rusak
        $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => $kondisi]), false, ['atap' => UploadedFile::fake()->image('a.jpg'), 'depan' => UploadedFile::fake()->image('d.jpg')])
            ->assertStatus(422)->assertJsonValidationErrors('foto.sisi_kanan');

        // Bukan gambar ditolak
        $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => $kondisi]), false, [
            'depan' => UploadedFile::fake()->image('d.jpg'), 'sisi_kanan' => UploadedFile::fake()->create('virus.exe', 10),
        ])->assertStatus(422)->assertJsonValidationErrors('foto.sisi_kanan');
        $this->assertSame('berjalan', $p->fresh()->status);

        // Lengkap: berhasil, dan foto bagian baik (opsional) ikut tersimpan dengan bagiannya
        $this->kirim($url, $this->bodyTanpaTagihan(['kondisi' => $kondisi]), true, ['atap' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $this->assertEqualsCanonicalizing(['depan', 'sisi_kanan', 'atap'], PengembalianFoto::pluck('bagian')->all());
    }

    public function test_preview_tidak_menuntut_foto_dan_biaya_bagian_baik_diabaikan(): void
    {
        $p = $this->berjalan();
        $kondisi = $this->semuaBaik();
        $kondisi[1] = ['bagian' => 'belakang', 'kondisi' => 'penyok', 'biaya' => 100000];
        $kondisi[2] = ['bagian' => 'sisi_kiri', 'kondisi' => 'baik', 'biaya' => 777777];

        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian/preview", $this->bodyTanpaTagihan(['kondisi' => $kondisi]), false)->assertOk()
            ->assertJsonPath('data.ringkasan.biaya_kerusakan', 100000);
        $this->assertSame(0, PengembalianFoto::count());
    }

    public function test_kolom_foto_kosong_dianggap_tidak_dikirim_dan_foto_tetap_wajib_untuk_bagian_rusak(): void
    {
        $p = $this->berjalan();
        $url = self::API."/{$p->id_pemesanan}/pengembalian";
        $kosong = array_fill_keys(PengembalianRequest::BAGIAN, ''); // Swagger mengirim kolom foto yang tak diisi sebagai teks kosong

        // Semua bagian baik, semua kolom foto kosong: lolos tanpa foto tersimpan
        $this->kirimDenganFotoKosong($url, $this->bodyTanpaTagihan(), $kosong)->assertOk();
        $this->assertSame(0, PengembalianFoto::count());

        // ban_velg rusak tanpa foto (kolom kosong): ditolak, hanya foto.ban_velg yang dilaporkan, pesanan tidak berubah
        $q = $this->berjalan();
        $kondisi = $this->semuaBaik();
        $kondisi[6] = ['bagian' => 'ban_velg', 'kondisi' => 'rusak', 'biaya' => 400000];
        $r = $this->kirimDenganFotoKosong(self::API."/{$q->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan(['kondisi' => $kondisi]), $kosong)->assertStatus(422);
        $this->assertSame(['foto.ban_velg'], array_keys($r->json('errors')));
        $this->assertSame('berjalan', $q->fresh()->status);

        // Berkas yang ada tetap diperiksa sebagai gambar
        $this->kirim(self::API."/{$q->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan(['kondisi' => $kondisi]), false, ['ban_velg' => UploadedFile::fake()->create('x.exe', 10)])
            ->assertStatus(422)->assertJsonValidationErrors('foto.ban_velg');
    }

    public function test_tol_dan_parkir_tidak_lagi_biaya_tambahan(): void
    {
        $this->assertSame(['cuci', 'kelengkapan_hilang', 'biaya_antar', 'lainnya'], PengembalianRequest::JENIS_TAMBAHAN);

        $p = $this->berjalan();
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token, 'Content-Type' => 'multipart/form-data']);
        $body = $this->bodyTanpaTagihan();
        $kondisi = array_column($body['kondisi'], 'kondisi', 'bagian');
        unset($body['kondisi']);

        $this->app['auth']->forgetGuards();
        $this->call('POST', self::API."/{$p->id_pemesanan}/pengembalian/preview", $body + [
            'kondisi' => $kondisi, 'biaya_tambahan' => ['cuci' => '20000', 'tol' => '50000', 'parkir' => '30000'],
        ], [], [], $server)->assertOk()->assertJsonPath('data.ringkasan.biaya_lain', 20000);

        $this->app['auth']->forgetGuards();
        $this->call('POST', self::API."/{$p->id_pemesanan}/pengembalian", $body + [
            'kondisi' => $kondisi, 'biaya_tambahan' => ['cuci' => '20000', 'tol' => '50000', 'parkir' => '30000'],
        ], [], [], $server)->assertOk()->assertJsonPath('data.ringkasan.biaya_lain', 20000);
        $this->assertSame(['cuci'], BiayaTambahan::pluck('jenis')->all(), 'Tol dan parkir diabaikan, tidak pernah tercatat');
    }

    public function test_status_selain_berjalan_ditolak_409_dan_tidak_ada_404(): void
    {
        [$budi] = $this->buatPelanggan();
        $konfirmasi = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi']);

        foreach (['/pengembalian', '/pengembalian/preview'] as $akhir) {
            $this->kirim(self::API."/{$konfirmasi->id_pemesanan}{$akhir}", $this->bodyTanpaTagihan())->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        }
        $this->kirim(self::API.'/9999/pengembalian', $this->bodyTanpaTagihan())->assertNotFound();

        // Diselesaikan dua kali
        $p = $this->berjalan();
        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan())->assertOk();
        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan())->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->assertSame(1, Pengembalian::where('id_pemesanan', $p->id_pemesanan)->count());
    }

    public function test_dengan_sopir_tanpa_jaminan_bbm_dan_odometer(): void
    {
        $p = $this->berjalan(['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null, 'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun']);
        $this->assertSame(0, Jaminan::count());

        // Kolom lepas kunci tidak dituntut; biaya kekurangan BBM yang terkirim diabaikan (BBM sudah termasuk tarif sopir)
        $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", [
            'tgl_kembali' => now()->addDay()->toDateString(), 'jam_kembali' => '20:00', 'kondisi' => $this->semuaBaik(),
            'disetujui_penyewa' => true, 'biaya_kekurangan_bbm' => 99999,
        ])->assertOk()
            ->assertJsonPath('data.ringkasan.kekurangan_bbm', 0)->assertJsonPath('data.ringkasan.denda_keterlambatan', 300000)
            ->assertJsonPath('data.jaminan_status', null)->assertJsonPath('data.unit_status', 'tersedia');
        $this->assertSame(0, (int) Pengembalian::sole()->biaya_kekurangan_bbm);
    }

    public function test_galat_di_tengah_proses_menghapus_foto_yang_sudah_ditulis_dan_membatalkan_data(): void
    {
        $p = $this->berjalan();
        $km = (int) $p->kendaraan->km_terakhir;
        $kondisi = $this->semuaBaik();
        $kondisi[0] = ['bagian' => 'depan', 'kondisi' => 'penyok', 'biaya' => 200000];
        $kondisi[6] = ['bagian' => 'ban_velg', 'kondisi' => 'rusak', 'biaya' => 400000];

        // Galat tak terduga SETELAH foto per bagian ditulis, saat status pesanan menjadi selesai
        Pemesanan::updating(function () {
            $this->assertNotSame([], Storage::disk('local')->allFiles(), 'Berkas sudah ada di disk saat galat terjadi');

            throw new \RuntimeException('galat uji');
        });

        try {
            $this->kirim(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan(['kondisi' => $kondisi]))->assertStatus(500);
        } finally {
            Pemesanan::flushEventListeners();
        }

        $this->assertSame([], Storage::disk('local')->allFiles(), 'Tidak ada berkas yatim');
        $this->assertSame('berjalan', $p->fresh()->status);
        $this->assertSame(0, Pengembalian::count() + PengembalianFoto::count() + PengembalianKondisi::count(), 'Data ikut dibatalkan');
        $this->assertSame($km, (int) $p->kendaraan->fresh()->km_terakhir, 'Kilometer unit tidak berubah');
    }

    public function test_user_biasa_dilarang(): void
    {
        $p = $this->berjalan();
        [, $tokenUser] = $this->buatPelanggan('user@mail.test');

        $this->app['auth']->forgetGuards();
        $this->withToken($tokenUser)->postJson(self::API."/{$p->id_pemesanan}/pengembalian", $this->bodyTanpaTagihan())->assertForbidden();
        $this->assertSame('berjalan', $p->fresh()->status);
    }
}
