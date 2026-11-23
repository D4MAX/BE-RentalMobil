<?php

namespace Tests\Feature;

use App\Models\Jaminan;
use App\Models\LogAktivitas;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** POST .../tagihan/lunas, POST .../jaminan/kembalikan, objek aksi, dan jenis tugas dashboard. */
class AdminTagihanJaminanTest extends TestCase
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

    private function panggil(string $metode, string $url, array $isi = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($metode, $url, $isi);
    }

    private function aksi(Pemesanan $p): array
    {
        return $this->panggil('GET', self::API."/{$p->id_pemesanan}")->json('data.aksi');
    }

    /** Pesanan selesai (lepas kunci, jaminan ditahan), dengan tagihan tambahan $tagihan (null = tanpa tagihan). */
    private function selesai(?int $tagihan = 530000, array $ubah = []): Pemesanan
    {
        [$pelanggan] = $this->buatPelanggan('p'.uniqid().'@mail.test');
        $p = $this->buatPesananBerjalan($pelanggan, $this->buatTipe(1, 'T'.uniqid()), $ubah);
        $p->update(['status' => 'selesai']);
        if ($tagihan) {
            Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => $tagihan, 'status' => 'menunggu']);
        }

        return $p;
    }

    // ---- tagihan/lunas ----

    public function test_tagihan_lunas_otomatis_mengembalikan_jaminan(): void
    {
        $p = $this->selesai();

        $aksi = $this->aksi($p);
        $this->assertTrue($aksi['bisa_tandai_lunas']);
        $this->assertFalse($aksi['bisa_kembalikan_jaminan'], 'Jaminan ditahan sampai tagihan lunas');

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tagihan/lunas", ['metode' => 'tunai'])->assertOk()
            ->assertJsonPath('data.tagihan_status', 'lunas')->assertJsonPath('data.jaminan_status', 'dikembalikan');
        $this->assertSame(4, Jaminan::where('id_pemesanan', $p->id_pemesanan)->where('dikembalikan', true)->count(), 'Jaminan otomatis kembali');

        $tagihan = Pembayaran::where('id_pemesanan', $p->id_pemesanan)->where('jenis', 'tambahan')->sole();
        $this->assertSame(['lunas', 'tunai'], [$tagihan->status, $tagihan->metode]);
        $this->assertNotNull($tagihan->tgl_bayar);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'pelaku' => 'admin', 'aksi' => 'Tagihan tambahan ditandai lunas (tunai)']);

        $aksi = $this->aksi($p);
        $this->assertFalse($aksi['bisa_tandai_lunas']);
        $this->assertFalse($aksi['bisa_kembalikan_jaminan'], 'Tidak ada lagi jaminan yang ditahan');
    }

    public function test_tagihan_lunas_tanpa_metode_dan_galat(): void
    {
        $p = $this->selesai();
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tagihan/lunas", ['metode' => 'kartu'])->assertStatus(422)->assertJsonValidationErrors('metode');
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tagihan/lunas")->assertOk();

        // Sudah lunas, tidak ada tagihan, atau pesanan belum selesai
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tagihan/lunas")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $tanpa = $this->selesai(null);
        $this->panggil('POST', self::API."/{$tanpa->id_pemesanan}/tagihan/lunas")->assertStatus(409)->assertJsonPath('message', 'Tidak ada tagihan tambahan yang belum lunas');
        [$budi] = $this->buatPelanggan('budi@mail.test');
        $berjalan = $this->buatPesananBerjalan($budi, $this->buatTipe(1, 'Lain'));
        $this->panggil('POST', self::API."/{$berjalan->id_pemesanan}/tagihan/lunas")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->panggil('POST', self::API.'/9999/tagihan/lunas')->assertNotFound();

        [, $tokenUser] = $this->buatPelanggan('user@mail.test');
        $lain = $this->selesai();
        $this->panggil('POST', self::API."/{$lain->id_pemesanan}/tagihan/lunas", [], $tokenUser)->assertForbidden();
    }

    // ---- jaminan/kembalikan ----

    public function test_jaminan_tidak_bisa_dikembalikan_sebelum_tagihan_lunas(): void
    {
        $p = $this->selesai();

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/jaminan/kembalikan")->assertStatus(409)
            ->assertJsonPath('code', 'TAGIHAN_BELUM_LUNAS')->assertJsonPath('message', 'Jaminan ditahan sampai tagihan lunas');
        $this->assertSame(0, Jaminan::where('dikembalikan', true)->count());

        // Data lama: tagihan sudah lunas tetapi jaminan masih ditahan (pelunasan sebelum aturan otomatis). Jalur manual masih bisa memperbaikinya.
        Pembayaran::where('id_pemesanan', $p->id_pemesanan)->where('jenis', 'tambahan')->update(['status' => 'lunas', 'metode' => 'transfer', 'tgl_bayar' => now()]);
        $this->assertTrue($this->aksi($p)['bisa_kembalikan_jaminan']);
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/jaminan/kembalikan")->assertOk()->assertJsonPath('data.jaminan_status', 'dikembalikan');

        $this->assertSame(4, Jaminan::where('id_pemesanan', $p->id_pemesanan)->where('dikembalikan', true)->count());
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'aksi' => 'Jaminan dikembalikan']);
        $this->panggil('GET', self::API."/{$p->id_pemesanan}")->assertJsonPath('data.jaminan.status', 'dikembalikan')->assertJsonPath('data.aksi.bisa_kembalikan_jaminan', false);

        // Dua kali: tidak ada lagi jaminan yang ditahan
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/jaminan/kembalikan")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
    }

    public function test_jaminan_tanpa_tagihan_langsung_bisa_dikembalikan(): void
    {
        $p = $this->selesai(null);
        $this->assertTrue($this->aksi($p)['bisa_kembalikan_jaminan']);

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/jaminan/kembalikan")->assertOk()->assertJsonPath('data.jaminan_status', 'dikembalikan');
    }

    public function test_jaminan_status_dan_jenis_sewa_yang_salah_ditolak(): void
    {
        [$budi] = $this->buatPelanggan('budi@mail.test');
        $berjalan = $this->buatPesananBerjalan($budi, $this->buatTipe(1, 'A'));
        $this->panggil('POST', self::API."/{$berjalan->id_pemesanan}/jaminan/kembalikan")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->assertFalse($this->aksi($berjalan)['bisa_kembalikan_jaminan']);

        // Dengan sopir tidak memakai jaminan
        $sopir = $this->selesai(null, ['jenis_sewa' => 'dengan_sopir', 'transmisi' => null, 'metode_ambil' => null, 'tujuan' => 'Batu', 'lokasi_jemput' => 'Stasiun']);
        $this->assertSame(0, Jaminan::where('id_pemesanan', $sopir->id_pemesanan)->count());
        $this->panggil('POST', self::API."/{$sopir->id_pemesanan}/jaminan/kembalikan")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->assertFalse($this->aksi($sopir)['bisa_kembalikan_jaminan']);

        $this->panggil('POST', self::API.'/9999/jaminan/kembalikan')->assertNotFound();
        [, $tokenUser] = $this->buatPelanggan('user@mail.test');
        $p = $this->selesai(null);
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/jaminan/kembalikan", [], $tokenUser)->assertForbidden();
        $this->assertSame(0, LogAktivitas::where('aksi', 'Jaminan dikembalikan')->count());
    }

    // ---- dashboard ----

    public function test_dashboard_memuat_jenis_tugas_dalam_urutan_dan_hanya_yang_relevan(): void
    {
        [$budi] = $this->buatPelanggan('budi@mail.test');
        $t = $this->buatTipe(30);
        $hariIni = now()->toDateString();

        // Serahkan unit: mulai hari ini dan besok masuk; lusa dan kemarin tidak
        $s0 = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => $hariIni, 'jam_serah' => '10:30']);
        $s1 = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => now()->addDay()->toDateString()]);
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => now()->addDays(2)->toDateString()]);
        $this->buatPesanan($budi, $t, ['status' => 'menunggu_pembayaran', 'tgl_mulai' => $hariIni]);

        // Pengembalian jatuh tempo: kembali hari ini dan terlambat masuk; besok tidak
        $jt = $this->buatPesananBerjalan($budi, $t);                                                      // kembali hari ini
        $tl = $this->buatPesananBerjalan($budi, $t, ['tgl_mulai' => now()->subDays(5)->toDateString()]); // kembali 3 hari lalu
        $this->buatPesananBerjalan($budi, $t, ['tgl_mulai' => now()->subDay()->toDateString()]);         // kembali besok

        // Tagihan belum dibayar dan jaminan siap dikembalikan
        $tg = $this->selesai(530000);
        $js = $this->selesai(null);
        $sudah = $this->selesai(null);
        Jaminan::where('id_pemesanan', $sudah->id_pemesanan)->update(['dikembalikan' => true]);

        $tugas = $this->panggil('GET', '/api/v1/admin/dashboard')->assertOk()->json('data.tugas_admin');
        $ringkas = array_map(fn ($x) => [$x['jenis'], $x['id']], $tugas);

        $this->assertSame([
            ['serahkan_unit', $s0->id_pemesanan], ['serahkan_unit', $s1->id_pemesanan],
            ['pengembalian_jatuh_tempo', $tl->id_pemesanan], ['pengembalian_jatuh_tempo', $jt->id_pemesanan],
            ['tagihan_belum_dibayar', $tg->id_pemesanan],
            ['jaminan_siap_dikembalikan', $js->id_pemesanan],
        ], $ringkas);

        $ket = array_column($tugas, 'keterangan');
        $this->assertStringContainsString('hari ini jam 10:30', $ket[0]);
        $this->assertStringContainsString('besok', $ket[1]);
        $this->assertStringContainsString('terlambat 3 hari', $ket[2]);
        $this->assertStringContainsString('jatuh tempo hari ini', $ket[3]);
        $this->assertStringContainsString('Rp530.000', $ket[4]);
        $this->assertSame(Pembayaran::where('jenis', 'tambahan')->value('id_pembayaran'), $tugas[4]['id_pembayaran']);
        $this->assertStringContainsString($js->nomor, $ket[5]);
    }

    public function test_tugas_tagihan_dan_jaminan_hilang_setelah_ditangani(): void
    {
        $p = $this->selesai(530000);
        $jenis = fn () => array_column($this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin'), 'jenis');

        $this->assertSame(['tagihan_belum_dibayar'], $jenis(), 'Jaminan belum siap selama tagihan belum lunas');

        // Tagihan lunas: jaminan otomatis ikut dikembalikan, jadi tidak ada lagi tugas untuk pesanan ini
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tagihan/lunas")->assertOk();
        $this->assertSame([], $jenis());
    }

    public function test_tugas_jaminan_siap_dikembalikan_hanya_untuk_data_lama_dan_hilang_setelah_dikembalikan_manual(): void
    {
        // Data lama: tagihan sudah lunas tetapi jaminan masih ditahan
        $p = $this->selesai(530000);
        Pembayaran::where('id_pemesanan', $p->id_pemesanan)->where('jenis', 'tambahan')->update(['status' => 'lunas', 'metode' => 'tunai', 'tgl_bayar' => now()]);
        $jenis = fn () => array_column($this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin'), 'jenis');

        $this->assertSame(['jaminan_siap_dikembalikan'], $jenis());
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/jaminan/kembalikan")->assertOk();
        $this->assertSame([], $jenis());
    }

    public function test_serahkan_unit_dan_jatuh_tempo_memakai_tanggal_jakarta(): void
    {
        // 13 Nov 18:30 UTC = 14 Nov 01:30 WIB: "hari ini" 14 Nov, "besok" 15 Nov
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'UTC'));
        [$budi] = $this->buatPelanggan('budi@mail.test');
        [, $token] = $this->buatAdmin('admin2@mail.test');
        $t = $this->buatTipe(10);

        $wibHariIni = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-14']);
        $wibBesok = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-15']);
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-16']);
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-13']); // kemarin menurut WIB

        $tugas = $this->panggil('GET', '/api/v1/admin/dashboard', [], $token)->json('data.tugas_admin');

        $this->assertSame([$wibHariIni->id_pemesanan, $wibBesok->id_pemesanan], array_column($tugas, 'id'));
        $this->assertStringContainsString('hari ini', $tugas[0]['keterangan']);
    }
}
