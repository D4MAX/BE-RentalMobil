<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Pembayaran;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** GET /pemesanan dan GET /pemesanan/{id} (Spek API bagian 6). */
class PemesananBacaTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/pemesanan';

    private function ambil(string $url, string $token)
    {
        $this->app['auth']->forgetGuards(); // guard menyimpan user dari permintaan sebelumnya dalam satu tes

        return $this->withToken($token)->getJson($url);
    }

    public function test_daftar_hanya_milik_sendiri_urut_terbaru_dengan_tab_dan_halaman(): void
    {
        [$budi, $tokenBudi] = $this->buatPelanggan('budi@mail.test');
        [$sari] = $this->buatPelanggan('sari@mail.test');
        $t = $this->buatTipe(5);

        $b1 = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $b2 = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $b3 = $this->buatPesanan($budi, $t, ['status' => 'dibatalkan']);
        $b4 = $this->buatPesanan($budi, $t); // menunggu pembayaran
        $this->buatPesanan($sari, $t);

        $semua = $this->ambil(self::API, $tokenBudi)->assertOk();
        $this->assertSame([$b4->id_pemesanan, $b3->id_pemesanan, $b2->id_pemesanan, $b1->id_pemesanan], $semua->json('data.*.id'));
        $semua->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 4])
            ->assertJsonStructure(['data' => [['id', 'nomor', 'status', 'tipe' => ['id', 'nama'], 'tgl_mulai', 'durasi', 'total_biaya', 'aksi_utama']]]);

        $this->assertSame([$b4->id_pemesanan, $b2->id_pemesanan], $this->ambil(self::API.'?tab=berlangsung', $tokenBudi)->json('data.*.id'));
        $this->assertSame([$b3->id_pemesanan, $b1->id_pemesanan], $this->ambil(self::API.'?tab=riwayat', $tokenBudi)->json('data.*.id'));

        $hal = $this->ambil(self::API.'?per_page=3&page=2', $tokenBudi)->assertOk();
        $this->assertSame([$b1->id_pemesanan], $hal->json('data.*.id'));
        $hal->assertJsonPath('meta', ['page' => 2, 'per_page' => 3, 'total' => 4]);

        $this->ambil(self::API.'?tab=lain', $tokenBudi)->assertStatus(422)->assertJsonValidationErrors('tab');
        $this->ambil(self::API.'?per_page=51', $tokenBudi)->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_aksi_utama_menurut_status(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(5);
        $this->buatPesanan($budi, $t); // menunggu, dalam batas
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $this->buatPesanan($budi, $t, ['status' => 'berjalan']);
        $selesai = $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        Review::create(['id_pemesanan' => $selesai->id_pemesanan, 'rating' => 5, 'tgl_review' => now()->toDateString()]);
        $this->buatPesanan($budi, $t, ['status' => 'dibatalkan']);

        $aksi = collect($this->ambil(self::API, $token)->json('data'))->pluck('aksi_utama', 'id')->all();
        // terbaru dulu: dibatalkan, selesai (belum review), selesai (sudah review), berjalan, dikonfirmasi, menunggu
        $this->assertSame(['pesan_lagi', 'beri_review', 'pesan_lagi', null, 'ajukan_pembatalan', 'bayar'], array_values($aksi));
    }

    public function test_detail_memuat_bentuk_user_tanpa_data_internal(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $p = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'catatan_admin' => 'RAHASIA', 'lokasi_jemput' => 'Jl. Veteran No. 5']);
        $p->pembayaran->first()->update(['status' => 'lunas', 'metode' => 'qris']);

        $r = $this->ambil(self::API.'/'.$p->id_pemesanan, $token)->assertOk()
            ->assertJsonPath('data.nomor', sprintf('PSN-%04d', $p->id_pemesanan))
            ->assertJsonPath('data.tipe', ['id' => $t->id_tipe, 'nama' => 'Brio'])
            ->assertJsonPath('data.jam_serah', '09:00')
            ->assertJsonPath('data.tgl_kembali', $p->tgl_kembali->toDateString())
            ->assertJsonPath('data.pembayaran', ['metode' => 'qris', 'status' => 'lunas', 'order_id' => $p->nomor.'-1'])
            ->assertJsonPath('data.plat_nomor', null)
            ->assertJsonPath('data.aksi', [
                'bisa_bayar' => false, 'bisa_batalkan' => false, 'bisa_ajukan_pembatalan' => true,
                'bisa_review' => false, 'bisa_unduh_nota' => true, 'bisa_perpanjang' => false, 'bisa_pesan_lagi' => false,
            ])
            ->assertJsonStructure(['data' => ['jenis_sewa', 'transmisi', 'metode_ambil', 'tujuan', 'total_biaya', 'tagihan_tambahan', 'jaminan', 'hasil_pemeriksaan']]);

        $this->assertStringNotContainsString('RAHASIA', $r->getContent());
        $this->assertArrayNotHasKey('catatan_admin', $r->json('data'));
        $this->assertArrayNotHasKey('log_aktivitas', $r->json('data'));
        $this->assertArrayNotHasKey('no_identitas', $r->json('data'));
    }

    public function test_plat_nomor_hanya_terlihat_saat_berjalan_atau_sesudahnya(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $p = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'id_kendaraan' => $unit->id_kendaraan]);

        $this->ambil(self::API.'/'.$p->id_pemesanan, $token)->assertJsonPath('data.plat_nomor', null);
        foreach (['berjalan', 'selesai'] as $status) {
            $p->update(['status' => $status]);
            $this->ambil(self::API.'/'.$p->id_pemesanan, $token)->assertJsonPath('data.plat_nomor', $unit->plat_nomor);
        }
    }

    public function test_tagihan_tambahan_dan_pembayaran_terbaru_ditampilkan(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'selesai']);
        Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 250000, 'status' => 'menunggu']);
        // Percobaan bayar kedua yang gagal tidak boleh menutupi pembayaran yang lunas
        $p->pembayaran->first()->update(['status' => 'lunas', 'metode' => 'qris']);
        Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000, 'order_id_midtrans' => $p->nomor.'-2', 'status' => 'gagal']);

        $this->ambil(self::API.'/'.$p->id_pemesanan, $token)
            ->assertJsonPath('data.tagihan_tambahan', ['jumlah' => 250000, 'status' => 'menunggu'])
            ->assertJsonPath('data.pembayaran.order_id', $p->nomor.'-1');
    }

    public function test_pesanan_orang_lain_tidak_ditemukan_dan_hak_akses(): void
    {
        [$budi] = $this->buatPelanggan('budi@mail.test');
        [, $tokenSari] = $this->buatPelanggan('sari@mail.test');
        $p = $this->buatPesanan($budi, $this->buatTipe(1));

        $this->ambil(self::API.'/'.$p->id_pemesanan, $tokenSari)->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->ambil(self::API.'/9999', $tokenSari)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders(); // buang token dari permintaan sebelumnya
        $this->getJson(self::API)->assertUnauthorized()->assertJsonPath('code', 'BELUM_LOGIN');
        $admin = Akun::create(['nama' => 'Adm', 'email' => 'adm@mail.test', 'password' => 'abc12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);
        $this->ambil(self::API, $admin->createToken('admin', ['admin'])->plainTextToken)->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
    }

    /** H-1 dihitung menurut tanggal kalender Asia/Jakarta, bukan UTC. */
    public function test_batas_h_minus_1_memakai_tanggal_jakarta(): void
    {
        // 13 Nov 18:30 UTC = 14 Nov 01:30 WIB
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'UTC'));
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(3);

        // Mulai 15 Nov: hari ini (14 Nov WIB) masih H-1. Mulai 14 Nov: sudah H-0.
        $besok = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-15']);
        $hariIni = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-14']);

        $this->ambil(self::API.'/'.$besok->id_pemesanan, $token)->assertJsonPath('data.aksi.bisa_ajukan_pembatalan', true);
        // Menurut UTC (hari ini 13 Nov) pesanan 14 Nov masih H-1; menurut WIB sudah lewat
        $this->ambil(self::API.'/'.$hariIni->id_pemesanan, $token)->assertJsonPath('data.aksi.bisa_ajukan_pembatalan', false);
    }

    public function test_bisa_perpanjang_hanya_saat_berjalan(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(8);

        foreach (['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'pengajuan_pembatalan', 'selesai', 'dibatalkan'] as $status) {
            $p = $this->buatPesanan($budi, $t, ['status' => $status]);
            $this->ambil(self::API.'/'.$p->id_pemesanan, $token)->assertJsonPath('data.aksi.bisa_perpanjang', $status === 'berjalan');
        }

        // Urutan kunci di objek aksi
        $r = $this->ambil(self::API.'/'.$p->id_pemesanan, $token);
        $this->assertSame(['bisa_bayar', 'bisa_batalkan', 'bisa_ajukan_pembatalan', 'bisa_review', 'bisa_unduh_nota', 'bisa_perpanjang', 'bisa_pesan_lagi'], array_keys($r->json('data.aksi')));
    }
}
