<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\LogAktivitas;
use App\Models\Pembayaran;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** GET /admin/pemesanan, GET /admin/pemesanan/{id}, PUT .../catatan. */
class AdminPemesananBacaTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/admin/pemesanan';

    private function sebagai(string $token, string $metode, string $url, array $isi = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metode, $url, $isi);
    }

    public function test_hanya_admin_boleh_user_dan_guest_ditolak(): void
    {
        [, $tokenUser] = $this->buatPelanggan();
        $this->sebagai($tokenUser, 'GET', self::API)->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
        $this->sebagai($tokenUser, 'GET', '/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson(self::API)->assertUnauthorized()->assertJsonPath('code', 'BELUM_LOGIN');

        // Admin dengan kata sandi sementara hanya boleh logout dan ganti kata sandi
        [$sementara, $tokenSementara] = $this->buatAdmin('temp@mail.test');
        $sementara->update(['wajib_ganti_password' => true]);
        $this->sebagai($tokenSementara, 'GET', self::API)->assertForbidden()->assertJsonPath('code', 'GANTI_PASSWORD_DULU');
    }

    public function test_daftar_ringkas_tanpa_aksi_dengan_filter_status_pencarian_dan_halaman(): void
    {
        [, $token] = $this->buatAdmin();
        [$budi] = $this->buatPelanggan('budi@mail.test');
        [$sari] = $this->buatPelanggan('sari@mail.test');
        $sari->update(['nama' => 'Sari Wulandari']);
        $t = $this->buatTipe(3);

        $a = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $b = $this->buatPesananBerjalan($sari, $t);
        $c = $this->buatPesanan($budi, $t, ['status' => 'pengajuan_pembatalan']);

        $semua = $this->sebagai($token, 'GET', self::API)->assertOk();
        $this->assertSame([$c->id_pemesanan, $b->id_pemesanan, $a->id_pemesanan], $semua->json('data.*.id'));
        $semua->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 3])
            ->assertJsonStructure(['data' => [['id', 'nomor', 'tgl_pesan', 'pelanggan' => ['nama'], 'tipe' => ['id', 'nama'], 'plat_nomor', 'tgl_mulai', 'status']]]);
        $this->assertArrayNotHasKey('aksi', $semua->json('data.0'));
        $this->assertSame($t->kendaraan()->first()->plat_nomor, $semua->json('data.1.plat_nomor'));
        $this->assertNull($semua->json('data.2.plat_nomor'));

        $this->assertSame([$b->id_pemesanan], $this->sebagai($token, 'GET', self::API.'?status=berjalan')->json('data.*.id'));
        $this->assertSame([$b->id_pemesanan], $this->sebagai($token, 'GET', self::API.'?q=Wulan')->json('data.*.id'));
        $this->assertSame([$a->id_pemesanan], $this->sebagai($token, 'GET', self::API.'?q='.$a->nomor)->json('data.*.id'));
        $this->assertSame([$a->id_pemesanan], $this->sebagai($token, 'GET', self::API.'?q='.$a->id_pemesanan)->json('data.*.id'));
        $this->assertSame([], $this->sebagai($token, 'GET', self::API.'?q=tidakada')->json('data'));
        $this->assertSame([$a->id_pemesanan], $this->sebagai($token, 'GET', self::API.'?per_page=2&page=2')->json('data.*.id'));

        $this->sebagai($token, 'GET', self::API.'?status=aneh')->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_detail_memuat_pelanggan_dengan_no_hp_dan_log_beserta_pelakunya(): void
    {
        [$admin, $token] = $this->buatAdmin('admin@mail.test', 'Rina Admin');
        [$budi] = $this->buatPelanggan('budi@mail.test', 'Kota Batu');
        $budi->update(['no_identitas' => '3573010000000009']);
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi', 'catatan_admin' => 'Bukti booking valid']);
        $this->lunasi($p, 'qris');
        LogAktivitas::catat('Pesanan dibuat', null, 'pemesanan', $p->id_pemesanan); // sistem
        LogAktivitas::create(['id_akun' => $budi->id_akun, 'pelaku' => 'pelanggan', 'aksi' => 'Pelanggan membatalkan', 'objek' => 'pemesanan', 'id_objek' => $p->id_pemesanan, 'waktu' => now()]);
        LogAktivitas::catat('Catatan admin diperbarui', $admin, 'pemesanan', $p->id_pemesanan);
        LogAktivitas::catat('Refund otomatis', null, 'pembayaran', $p->pembayaran->first()->id_pembayaran);
        LogAktivitas::catat('Aksi pesanan lain', $admin, 'pemesanan', $p->id_pemesanan + 99);

        $r = $this->sebagai($token, 'GET', self::API.'/'.$p->id_pemesanan)->assertOk()
            ->assertJsonPath('data.pelanggan', ['nama' => 'Budi', 'no_hp' => '0812345678', 'email' => 'budi@mail.test',
                'provinsi' => 'Jawa Timur', 'kota' => 'Kota Batu', 'no_identitas' => '3573010000000009'])
            ->assertJsonPath('data.catatan_admin', 'Bukti booking valid')
            ->assertJsonPath('data.domisili', 'malang_raya')
            ->assertJsonPath('data.aksi.bisa_serahkan', true)
            ->assertJsonPath('data.aksi.bisa_setujui', false)
            ->assertJsonPath('data.serah_terima', null)
            ->assertJsonPath('data.jaminan', null)
            ->assertJsonPath('data.pengembalian', null)
            ->assertJsonPath('data.review', null);

        $this->assertSame(
            [['Pesanan dibuat', 'Sistem'], ['Pelanggan membatalkan', 'Pelanggan'], ['Catatan admin diperbarui', 'Rina Admin'], ['Refund otomatis', 'Sistem']],
            collect($r->json('data.log_aktivitas'))->map(fn ($l) => [$l['aksi'], $l['oleh']])->all()
        );
        $this->assertArrayHasKey('waktu', $r->json('data.log_aktivitas.0'));

        // Spek: tidak ada tombol pembatalan oleh admin
        $this->assertSame(
            ['bisa_serahkan', 'bisa_selesaikan', 'bisa_setujui', 'bisa_tolak', 'bisa_refund_selesai', 'bisa_tandai_lunas', 'bisa_kembalikan_jaminan'],
            array_keys($r->json('data.aksi'))
        );
        $this->assertArrayNotHasKey('bisa_batalkan', $r->json('data.aksi'));
        $this->assertArrayNotHasKey('bisa_kirim_pengingat', $r->json('data.aksi'), 'V22 tidak punya pengingat');

        $this->sebagai($token, 'GET', self::API.'/9999')->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
    }

    public function test_aksi_menurut_status(): void
    {
        [, $token] = $this->buatAdmin();
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(5);

        $cek = fn ($p) => collect($this->sebagai($token, 'GET', self::API.'/'.$p->id_pemesanan)->json('data.aksi'))->filter()->keys()->all();

        $this->assertSame([], $cek($this->buatPesanan($budi, $t))); // menunggu pembayaran
        $this->assertSame(['bisa_serahkan'], $cek($this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi'])));
        $this->assertSame(['bisa_selesaikan'], $cek($this->buatPesananBerjalan($budi, $t)));
        $this->assertSame(['bisa_setujui', 'bisa_tolak'], $cek($this->buatPesanan($budi, $t, ['status' => 'pengajuan_pembatalan'])));
        $this->assertSame([], $cek($this->buatPesanan($budi, $t, ['status' => 'selesai'])));
    }

    public function test_detail_pesanan_selesai_memuat_serah_terima_jaminan_dan_pengembalian_dan_review(): void
    {
        [, $token] = $this->buatAdmin();
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesananBerjalan($budi, $this->buatTipe(1));
        Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 250000, 'status' => 'menunggu']);

        $r = $this->sebagai($token, 'GET', self::API.'/'.$p->id_pemesanan)->assertOk();
        $r->assertJsonPath('data.serah_terima.odometer_awal', 45800)
            ->assertJsonPath('data.serah_terima.bbm_awal', 'penuh')
            ->assertJsonPath('data.jaminan.status', 'ditahan')
            ->assertJsonPath('data.tagihan_tambahan', ['jumlah' => 250000, 'status' => 'menunggu'])
            ->assertJsonPath('data.plat_nomor', $p->kendaraan->plat_nomor);
        $this->assertCount(4, $r->json('data.jaminan.daftar'));
        $this->assertCount(2, $r->json('data.riwayat_pembayaran'));
    }

    public function test_simpan_catatan_admin_dan_tercatat_di_log(): void
    {
        [$admin, $token] = $this->buatAdmin();
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi']);

        $this->sebagai($token, 'PUT', self::API.'/'.$p->id_pemesanan.'/catatan', ['catatan_admin' => 'Sudah dihubungi, refund via transfer BCA'])
            ->assertOk()->assertJsonPath('data.catatan_admin', 'Sudah dihubungi, refund via transfer BCA');

        $this->assertSame('Sudah dihubungi, refund via transfer BCA', $p->fresh()->catatan_admin);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $admin->id_akun, 'pelaku' => 'admin', 'aksi' => 'Catatan admin diperbarui']);

        // Boleh dikosongkan, tetapi field harus dikirim; pesanan lain 404
        $this->sebagai($token, 'PUT', self::API.'/'.$p->id_pemesanan.'/catatan', ['catatan_admin' => null])->assertOk();
        $this->assertNull($p->fresh()->catatan_admin);
        $this->sebagai($token, 'PUT', self::API.'/'.$p->id_pemesanan.'/catatan', [])->assertStatus(422)->assertJsonValidationErrors('catatan_admin');
        $this->sebagai($token, 'PUT', self::API.'/9999/catatan', ['catatan_admin' => 'x'])->assertNotFound();
    }

    public function test_user_tidak_bisa_mengubah_catatan_dan_tidak_ada_endpoint_pembatalan_admin(): void
    {
        [$admin, $tokenAdmin] = $this->buatAdmin();
        [$budi, $tokenUser] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi']);

        $this->sebagai($tokenUser, 'PUT', self::API.'/'.$p->id_pemesanan.'/catatan', ['catatan_admin' => 'x'])->assertForbidden();

        foreach (['batalkan', 'batal', 'ajukan-pembatalan'] as $aksi) {
            $this->sebagai($tokenAdmin, 'POST', self::API.'/'.$p->id_pemesanan.'/'.$aksi)->assertNotFound();
        }
        $this->assertSame('dikonfirmasi', $p->fresh()->status);
    }
}
