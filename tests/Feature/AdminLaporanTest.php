<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Services\DokumenPdfService;
use App\Services\LaporanService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** GET /admin/laporan dan /admin/laporan/pdf (Spek API bagian 11). "Hari ini" dibekukan: 13 November 2026, siang WIB. */
class AdminLaporanTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/admin/laporan';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-13 12:00:00', 'Asia/Jakarta'));
        [, $this->token] = $this->buatAdmin();
    }

    private function ambil(string $url, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->getJson($url);
    }

    /** Pesanan dengan tgl_mulai tertentu; total 600000 (2 hari x 300000) kecuali diubah. */
    private function pesanan($pelanggan, $tipe, string $mulai, string $status, array $ubah = [])
    {
        return $this->buatPesanan($pelanggan, $tipe, ['status' => $status, 'tgl_mulai' => $mulai] + $ubah);
    }

    public function test_periode_bawaan_7_hari_dan_bentuk_respons_sesuai_spek(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(5, 'Innova');
        $unit = $t->kendaraan()->first();

        $this->pesanan($budi, $t, '2026-11-06', 'selesai');                       // di luar periode
        $dalam = $this->pesanan($budi, $t, '2026-11-08', 'selesai', ['id_kendaraan' => $unit->id_kendaraan, 'durasi' => 3]); // 900000
        Pembayaran::create(['id_pemesanan' => $dalam->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 180000, 'status' => 'lunas']);
        $this->pesanan($budi, $t, '2026-11-13', 'dikonfirmasi');                  // hari ini
        $this->pesanan($budi, $t, '2026-11-14', 'dikonfirmasi');                  // besok: di luar periode "sampai hari ini"

        $r = $this->ambil(self::API)->assertOk();

        $r->assertJsonPath('data.periode', ['dari' => '2026-11-07', 'sampai' => '2026-11-13']);
        $r->assertJsonPath('data.ringkasan.total_pendapatan', 900000 + 180000 + 600000)
            ->assertJsonPath('data.ringkasan.jumlah_transaksi', 2)->assertJsonPath('data.ringkasan.jumlah_dibatalkan', 0)
            ->assertJsonPath('data.ringkasan.per_status', ['dikonfirmasi' => 1, 'selesai' => 1]);
        $this->assertSame([
            ['tgl_mulai' => '2026-11-08', 'nomor' => $dalam->nomor, 'pelanggan' => 'Budi', 'unit' => "Innova {$unit->plat_nomor}", 'status' => 'selesai', 'sewa' => 900000, 'tagihan_tambahan' => 180000],
        ], [$r->json('data.data.0')]);
        $this->assertSame(0, $r->json('data.data.1.tagihan_tambahan'));
        $this->assertSame('Innova', $r->json('data.data.1.unit'), 'Belum punya plat: hanya nama tipe');
    }

    public function test_pendapatan_hanya_dari_dikonfirmasi_berjalan_dan_selesai(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(10);

        foreach (['dikonfirmasi', 'berjalan', 'selesai'] as $status) {
            $this->pesanan($budi, $t, '2026-11-10', $status);
        }
        foreach (['dibatalkan', 'dibatalkan', 'menunggu_pembayaran', 'pengajuan_pembatalan'] as $status) {
            $p = $this->pesanan($budi, $t, '2026-11-10', $status);
            // Tagihan pada pesanan yang tidak dihitung juga tidak boleh masuk pendapatan
            Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 111111, 'status' => 'menunggu']);
        }

        $r = $this->ambil(self::API)->assertOk();

        $r->assertJsonPath('data.ringkasan.total_pendapatan', 1800000)->assertJsonPath('data.ringkasan.jumlah_transaksi', 7)->assertJsonPath('data.ringkasan.jumlah_dibatalkan', 2);
        $this->assertSame(['menunggu_pembayaran' => 1, 'dikonfirmasi' => 1, 'berjalan' => 1, 'selesai' => 1, 'pengajuan_pembatalan' => 1, 'dibatalkan' => 2], $r->json('data.ringkasan.per_status'));
    }

    public function test_tagihan_tambahan_belum_lunas_tidak_dihitung_sebagai_pendapatan(): void
    {
        [$budi] = $this->buatPelanggan();
        $p = $this->pesanan($budi, $this->buatTipe(5), '2026-11-10', 'selesai');
        Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 200000, 'status' => 'menunggu']);

        $this->ambil(self::API)->assertOk()
            ->assertJsonPath('data.ringkasan.total_pendapatan', 600000)
            ->assertJsonPath('data.data.0.tagihan_tambahan', 0);
    }

    public function test_batas_periode_bulan_ini_bulan_lalu_dan_30_hari(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(20);
        foreach (['2026-09-30', '2026-10-01', '2026-10-14', '2026-10-15', '2026-10-31', '2026-11-01', '2026-11-13', '2026-11-14'] as $tgl) {
            $this->pesanan($budi, $t, $tgl, 'selesai');
        }
        $tanggal = fn ($q) => $this->ambil(self::API.$q)->json('data.data.*.tgl_mulai');

        $this->assertSame(['2026-11-13'], array_slice($tanggal(''), -1));
        $this->assertSame(['dari' => '2026-11-07', 'sampai' => '2026-11-13'], $this->ambil(self::API)->json('data.periode'));

        $this->assertSame(['2026-10-15', '2026-10-31', '2026-11-01', '2026-11-13'], $tanggal('?periode=30'));
        $this->assertSame(['dari' => '2026-10-15', 'sampai' => '2026-11-13'], $this->ambil(self::API.'?periode=30')->json('data.periode'));

        $this->assertSame(['2026-11-01', '2026-11-13'], $tanggal('?periode=bulan_ini'));
        $this->assertSame(['dari' => '2026-11-01', 'sampai' => '2026-11-13'], $this->ambil(self::API.'?periode=bulan_ini')->json('data.periode'));

        $this->assertSame(['2026-10-01', '2026-10-14', '2026-10-15', '2026-10-31'], $tanggal('?periode=bulan_lalu'));
        $this->assertSame(['dari' => '2026-10-01', 'sampai' => '2026-10-31'], $this->ambil(self::API.'?periode=bulan_lalu')->json('data.periode'));

        // Filter status
        $this->pesanan($budi, $t, '2026-11-10', 'dibatalkan');
        $r = $this->ambil(self::API.'?status=dibatalkan')->assertOk();
        $this->assertSame(1, $r->json('data.ringkasan.jumlah_transaksi'));
        $this->assertSame(['dibatalkan' => 1], $r->json('data.ringkasan.per_status'));
        $this->assertSame(0, $r->json('data.ringkasan.total_pendapatan'));
    }

    public function test_bulan_lalu_dari_awal_tahun_dan_tanggal_31(): void
    {
        $this->travelTo(Carbon::parse('2027-03-31 09:00:00', 'Asia/Jakarta'));
        $this->assertSame(['dari' => '2027-02-01', 'sampai' => '2027-02-28'], $this->ambil(self::API.'?periode=bulan_lalu')->json('data.periode'));

        $this->travelTo(Carbon::parse('2027-01-15 09:00:00', 'Asia/Jakarta'));
        $this->assertSame(['dari' => '2026-12-01', 'sampai' => '2026-12-31'], $this->ambil(self::API.'?periode=bulan_lalu')->json('data.periode'));
    }

    public function test_data_kosong_tetap_200_dengan_ringkasan_nol(): void
    {
        $this->ambil(self::API)->assertOk()
            ->assertJsonPath('data.ringkasan', ['total_pendapatan' => 0, 'jumlah_transaksi' => 0, 'jumlah_dibatalkan' => 0, 'per_status' => []])
            ->assertJsonPath('data.data', []);
    }

    public function test_validasi_dan_hak_akses(): void
    {
        $this->ambil(self::API.'?periode=90')->assertStatus(422)->assertJsonValidationErrors('periode');
        $this->ambil(self::API.'?status=aneh')->assertStatus(422)->assertJsonValidationErrors('status');
        $this->ambil(self::API.'/pdf?periode=90')->assertStatus(422);

        [, $tokenUser] = $this->buatPelanggan();
        foreach ([self::API, self::API.'/pdf'] as $url) {
            $this->ambil($url, $tokenUser)->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
        }
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson(self::API)->assertUnauthorized();
    }

    public function test_pdf_untuk_laporan_berisi_data_dan_kosong_409(): void
    {
        $this->ambil(self::API.'/pdf')->assertStatus(409)->assertJsonPath('code', 'DATA_KOSONG')->assertJsonPath('message', 'Tidak ada data pada periode ini');

        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(3, 'Innova');
        $p = $this->pesanan($budi, $t, '2026-11-10', 'selesai');
        $this->pesanan($budi, $t, '2026-11-11', 'dibatalkan');

        $r = $this->ambil(self::API.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString('laporan-2026-11-07_2026-11-13.pdf', $r->headers->get('Content-Disposition'));

        // Filter status ikut ke PDF, dan filter yang tidak menemukan apa pun: 409
        $this->ambil(self::API.'/pdf?status=selesai')->assertOk();
        $this->ambil(self::API.'/pdf?status=berjalan')->assertStatus(409)->assertJsonPath('code', 'DATA_KOSONG');

        // Isi: periode, tabel, jumlah transaksi, total pendapatan
        $html = app(DokumenPdfService::class)->laporanHtml(app(LaporanService::class)->susun('7', null), null);
        foreach (['2026-11-07', '2026-11-13', $p->nomor, 'Budi', 'Innova', 'Rp600.000', 'dibatalkan', 'Jumlah transaksi', 'Total pendapatan'] as $harus) {
            $this->assertStringContainsString($harus, $html);
        }
    }

    public function test_pdf_meloloskan_karakter_html_pada_nama_pelanggan(): void
    {
        [$budi] = $this->buatPelanggan();
        $budi->update(['nama' => '<b>Budi</b>']);
        $this->pesanan($budi, $this->buatTipe(1), '2026-11-10', 'selesai');

        $html = app(DokumenPdfService::class)->laporanHtml(app(LaporanService::class)->susun('7', null), null);

        $this->assertStringNotContainsString('<b>Budi</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;Budi&lt;/b&gt;', $html);
    }
}
