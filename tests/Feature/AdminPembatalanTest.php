<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Services\Pembayaran\PembayaranGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** setujui-pembatalan, tolak-pembatalan, refund-selesai, dan tugas_admin di dashboard. */
class AdminPembatalanTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private const API = '/api/v1/admin/pemesanan';

    private string $token;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->token] = $this->buatAdmin('admin@mail.test', 'Rina Admin');
    }

    private function panggil(string $metode, string $url, array $isi = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token ?? $this->token)->json($metode, $url, $isi);
    }

    /** Pesanan dengan pengajuan pembatalan dari pelanggan; dibayar dengan $metode, rekening hanya diisi untuk VA. */
    private function pengajuan(string $metode = 'qris', ?string $kota = 'Kota Malang', int $unit = 1): Pemesanan
    {
        [$pelanggan] = $this->buatPelanggan('p'.uniqid().'@mail.test', $kota);
        $p = $this->buatPesanan($pelanggan, $this->buatTipe($unit, 'T'.uniqid()), ['status' => 'pengajuan_pembatalan', 'status_sebelumnya' => 'dikonfirmasi', 'alasan_pengajuan' => 'Jadwal berubah']);
        $this->lunasi($p, $metode);
        $va = in_array($metode, ['bank_transfer', 'echannel', 'cstore'], true);
        // Seperti ajukan-pembatalan sungguhan: hanya rekening yang diisi, refund_cara baru terisi saat disetujui
        if ($va) {
            $p->pembayaran->first()->update(['bank' => 'BCA', 'no_rekening' => '1234567890', 'nama_rekening' => 'Budi Santoso']);
        }

        return $p;
    }

    private function bayar(Pemesanan $p): Pembayaran
    {
        return Pembayaran::where('id_pemesanan', $p->id_pemesanan)->where('jenis', 'sewa')->sole();
    }

    // ---- setujui ----

    public function test_setujui_qris_refund_lewat_midtrans_unit_dilepas_dan_tercatat(): void
    {
        $p = $this->pengajuan('qris');

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/setujui-pembatalan")->assertOk()
            ->assertJsonPath('data', ['status' => 'dibatalkan', 'refund' => ['metode' => 'qris', 'cara' => 'midtrans', 'selesai' => true]]);

        $p->refresh();
        $this->assertSame(['dibatalkan', 'pengajuan_disetujui', $this->admin->id_akun], [$p->status, $p->alasan_pembatalan, $p->id_admin]);
        $this->assertSame('Jadwal berubah', $p->alasan_pengajuan, 'Teks pelanggan tetap di kolomnya sendiri');
        $bayar = $this->bayar($p);
        $this->assertSame(['dikembalikan', 'midtrans', true], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai]);
        $this->assertSame([['orderId' => $bayar->order_id_midtrans, 'jumlah' => 600000, 'alasan' => 'Pembatalan disetujui']], app(PembayaranGateway::class)->refundDicatat);

        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'pelaku' => 'admin', 'aksi' => 'Pengajuan pembatalan disetujui, unit dilepas']);
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'pembayaran', 'id_objek' => $bayar->id_pembayaran, 'pelaku' => 'sistem', 'aksi' => 'Dana dikembalikan penuh lewat Midtrans']);

        // Unit dilepas: pelanggan lain bisa memesan tipe satu-satunya pada tanggal yang sama
        [, $tokenLain] = $this->buatPelanggan('lain@mail.test');
        $this->panggil('POST', '/api/v1/pemesanan', [
            'id_tipe' => $p->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual', 'tgl_mulai' => $p->tgl_mulai->toDateString(), 'durasi' => 2,
            'jam_serah' => '09:00', 'metode_ambil' => 'ambil_di_kantor', 'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ], $tokenLain)->assertCreated();
    }

    public function test_setujui_qris_saat_midtrans_gagal_jatuh_ke_transfer_admin_tanpa_galat(): void
    {
        $p = $this->pengajuan('qris');
        app(PembayaranGateway::class)->gagalRefund = true;

        // Pelanggan QRIS tidak punya rekening: admin menghubunginya di luar sistem
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/setujui-pembatalan")->assertOk()
            ->assertJsonPath('data', ['status' => 'dibatalkan', 'refund' => ['metode' => 'qris', 'cara' => 'transfer_admin', 'selesai' => false]]);

        $bayar = $this->bayar($p);
        $this->assertSame(['lunas', 'transfer_admin', false, null], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai, $bayar->no_rekening]);
        $this->assertSame('dibatalkan', $p->fresh()->status, 'Pembatalan tetap sah');
        $this->assertDatabaseHas('log_aktivitas', ['objek' => 'pembayaran', 'id_objek' => $bayar->id_pembayaran, 'aksi' => 'Refund otomatis lewat Midtrans gagal, dialihkan ke transfer admin']);

        // Muncul di dashboard dengan petunjuk menghubungi pelanggan (no_hp) di luar sistem
        $tugas = $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin');
        $this->assertCount(1, $tugas);
        $this->assertSame(['id' => $p->id_pemesanan, 'jenis' => 'refund_transfer'], ['id' => $tugas[0]['id'], 'jenis' => $tugas[0]['jenis']]);
        $this->assertStringContainsString('0812345678', $tugas[0]['keterangan']);
        $this->assertStringContainsString('catatan admin', $tugas[0]['keterangan']);

        // Admin mencatat hasil kontak di catatan admin lalu menandai selesai
        $this->panggil('PUT', self::API."/{$p->id_pemesanan}/catatan", ['catatan_admin' => 'Dihubungi 14/11, transfer ke OVO 0812345678'])->assertOk();
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai")->assertOk()->assertJsonPath('data.refund_selesai', true);
        $this->assertSame('dikembalikan', $this->bayar($p)->status);
        $this->assertSame([], $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin'));
    }

    public function test_setujui_virtual_account_tidak_memanggil_midtrans_dan_menunggu_transfer_admin(): void
    {
        $p = $this->pengajuan('bank_transfer', 'Kota Surabaya');

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/setujui-pembatalan")->assertOk()
            ->assertJsonPath('data.refund', ['metode' => 'bank_transfer', 'cara' => 'transfer_admin', 'selesai' => false]);

        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat);
        $bayar = $this->bayar($p);
        $this->assertSame(['lunas', 'transfer_admin', false, 'BCA'], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai, $bayar->bank]);

        // Admin melihat rekening tujuan di detail dan dashboard
        $this->panggil('GET', self::API."/{$p->id_pemesanan}")->assertJsonPath('data.refund', [
            'id_pembayaran' => $bayar->id_pembayaran, 'cara' => 'transfer_admin', 'selesai' => false, 'status_pembayaran' => 'lunas', 'jumlah' => 600000, 'metode' => 'bank_transfer',
            'bank' => 'BCA', 'no_rekening' => '1234567890', 'nama_rekening' => 'Budi Santoso',
        ])->assertJsonPath('data.aksi.bisa_refund_selesai', true);
        $tugas = $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin');
        $this->assertSame('refund_transfer', $tugas[0]['jenis']);
        $this->assertStringContainsString('BCA 1234567890 a.n. Budi Santoso', $tugas[0]['keterangan']);
        $this->assertStringContainsString('Rp600.000', $tugas[0]['keterangan']);
    }

    public function test_setujui_status_salah_dan_tidak_ada(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(5);

        foreach (['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'selesai', 'dibatalkan'] as $status) {
            $p = $this->buatPesanan($budi, $t, ['status' => $status]);
            $this->panggil('POST', self::API."/{$p->id_pemesanan}/setujui-pembatalan")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
            $this->assertSame($status, $p->fresh()->status);
        }
        $this->panggil('POST', self::API.'/9999/setujui-pembatalan')->assertNotFound();
        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat);

        // Disetujui dua kali
        $p = $this->pengajuan('qris');
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/setujui-pembatalan")->assertOk();
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/setujui-pembatalan")->assertStatus(409);
        $this->assertCount(1, app(PembayaranGateway::class)->refundDicatat, 'Refund tidak boleh ganda');
    }

    // ---- tolak ----

    public function test_tolak_mengembalikan_status_dan_menghapus_rekening_refund(): void
    {
        $p = $this->pengajuan('bank_transfer');

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tolak-pembatalan")->assertOk()->assertJsonPath('data.status', 'dikonfirmasi');

        $p->refresh();
        $this->assertSame(['dikonfirmasi', null, $this->admin->id_akun], [$p->status, $p->status_sebelumnya, $p->id_admin]);
        $bayar = $this->bayar($p);
        $this->assertSame(['lunas', null, null, null, null], [$bayar->status, $bayar->bank, $bayar->no_rekening, $bayar->nama_rekening, $bayar->refund_cara]);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'aksi' => 'Pengajuan pembatalan ditolak, status kembali ke dikonfirmasi']);
        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat);
        $this->panggil('GET', '/api/v1/admin/dashboard')->assertJsonPath('data.tugas_admin', []);

        // Pelanggan boleh mengajukan lagi sesudah ditolak, dan tolak kedua kali ditolak 409
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tolak-pembatalan")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
    }

    public function test_tolak_status_salah_dan_tidak_ada(): void
    {
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi']);

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/tolak-pembatalan")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->panggil('POST', self::API.'/9999/tolak-pembatalan')->assertNotFound();
    }

    // ---- refund selesai ----

    public function test_refund_selesai_hanya_untuk_refund_transfer_admin_yang_menunggu(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(5);

        // Tidak ada refund menunggu sama sekali (pesanan dikonfirmasi biasa)
        $biasa = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $this->lunasi($biasa);
        $this->panggil('POST', self::API."/{$biasa->id_pemesanan}/refund-selesai")->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        // Pengajuan yang belum disetujui belum punya utang refund (refund_cara belum diisi)
        $aju = $this->pengajuan('bank_transfer');
        $this->panggil('POST', self::API."/{$aju->id_pemesanan}/refund-selesai")->assertStatus(409);

        // Dibatalkan tetapi refund lewat Midtrans sudah selesai
        $qris = $this->pengajuan('qris');
        $this->panggil('POST', self::API."/{$qris->id_pemesanan}/setujui-pembatalan")->assertOk();
        $this->panggil('POST', self::API."/{$qris->id_pemesanan}/refund-selesai")->assertStatus(409);

        // Dibatalkan dan menunggu transfer: sekali berhasil, kedua kali 409
        $va = $this->pengajuan('echannel');
        $this->panggil('POST', self::API."/{$va->id_pemesanan}/setujui-pembatalan")->assertOk();
        $this->panggil('POST', self::API."/{$va->id_pemesanan}/refund-selesai")->assertOk()->assertJsonPath('data.refund_selesai', true);
        $bayar = $this->bayar($va);
        $this->assertSame(['dikembalikan', true], [$bayar->status, $bayar->refund_selesai]);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $va->id_pemesanan, 'id_akun' => $this->admin->id_akun, 'pelaku' => 'admin', 'aksi' => 'Refund ditransfer admin, ditandai selesai']);
        $this->panggil('POST', self::API."/{$va->id_pemesanan}/refund-selesai")->assertStatus(409);

        $this->panggil('POST', self::API.'/9999/refund-selesai')->assertNotFound();
    }

    public function test_refund_dari_pembayaran_terlambat_oleh_webhook_juga_bisa_ditandai_selesai(): void
    {
        // Pesanan dibatalkan kedaluwarsa, lalu pembayaran VA masuk dan jatah sudah habis (webhook menandai transfer_admin)
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa']);
        $this->lunasi($p, 'bank_transfer')->pembayaran->first()->update(['refund_cara' => 'transfer_admin', 'refund_selesai' => false]);

        $this->panggil('GET', '/api/v1/admin/dashboard')->assertJsonPath('data.tugas_admin.0.jenis', 'refund_transfer');
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai")->assertOk();
        $this->panggil('GET', '/api/v1/admin/dashboard')->assertJsonPath('data.tugas_admin', []);
    }

    // ---- dashboard ----

    public function test_dashboard_hitungan_dan_tugas_admin(): void
    {
        [$budi] = $this->buatPelanggan();
        $t = $this->buatTipe(4);
        $t->kendaraan()->orderBy('id_kendaraan')->first()->update(['status' => 'dalam_perawatan']);
        $this->buatPesanan($budi, $t); // menunggu pembayaran
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $this->buatPesanan($budi, $t, ['status' => 'selesai']);
        $aju = $this->pengajuan('qris');

        $r = $this->panggil('GET', '/api/v1/admin/dashboard')->assertOk();
        $r->assertJsonPath('data.hitungan', [
            'menunggu_pembayaran' => 1, 'dikonfirmasi' => 2, 'berjalan' => 0, 'pengajuan_pembatalan' => 1,
            'unit_tersedia' => 3 + 1, 'unit_dalam_servis' => 1,
        ])->assertJsonPath('data.pengingat_servis', []);
        $this->assertSame([['id' => $aju->id_pemesanan, 'jenis' => 'pengajuan_pembatalan']],
            collect($r->json('data.tugas_admin'))->map(fn ($x) => ['id' => $x['id'], 'jenis' => $x['jenis']])->all());
        $this->assertStringContainsString($aju->nomor, $r->json('data.tugas_admin.0.keterangan'));
    }

    public function test_semua_endpoint_pembatalan_admin_menolak_user_biasa(): void
    {
        $p = $this->pengajuan('qris');
        [, $tokenUser] = $this->buatPelanggan('user@mail.test');

        foreach (['setujui-pembatalan', 'tolak-pembatalan', 'refund-selesai'] as $aksi) {
            $this->panggil('POST', self::API."/{$p->id_pemesanan}/{$aksi}", [], $tokenUser)->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');
        }
        $this->assertSame('pengajuan_pembatalan', $p->fresh()->status);
    }

    // ---- kelebihan bayar (pembayaran ganda) pada pesanan yang tetap aktif ----

    public function test_refund_kelebihan_bayar_pada_pesanan_aktif_muncul_di_dashboard_dan_bisa_diselesaikan(): void
    {
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi']);
        $this->lunasi($p, 'qris');
        // Token kedua juga dibayar: webhook menandai refund transfer_admin walau pesanan tetap dikonfirmasi
        $ganda = Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000, 'metode' => 'bank_transfer',
            'order_id_midtrans' => $p->nomor.'-2', 'status' => 'lunas', 'tgl_bayar' => now(), 'refund_cara' => 'transfer_admin', 'refund_selesai' => false]);

        $tugas = $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin');
        $this->assertSame(['refund_transfer'], array_column($tugas, 'jenis'));
        $this->assertSame($ganda->id_pembayaran, $tugas[0]['id_pembayaran']);
        $this->assertStringContainsString('kelebihan bayar', $tugas[0]['keterangan']);
        $this->assertStringContainsString('dikonfirmasi', $tugas[0]['keterangan']);

        $this->panggil('GET', self::API."/{$p->id_pemesanan}")
            ->assertJsonPath('data.aksi.bisa_refund_selesai', true)
            ->assertJsonPath('data.refund.id_pembayaran', $ganda->id_pembayaran)
            ->assertJsonPath('data.refund_menunggu', [['id_pembayaran' => $ganda->id_pembayaran, 'jumlah' => 600000, 'order_id' => $p->nomor.'-2']]);

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai")->assertOk()
            ->assertJsonPath('data', ['refund_selesai' => true, 'id_pembayaran' => $ganda->id_pembayaran]);

        $this->assertSame('dikonfirmasi', $p->fresh()->status, 'Pesanan tidak ikut berubah');
        $this->assertSame('lunas', Pembayaran::where('id_pemesanan', $p->id_pemesanan)->orderBy('id_pembayaran')->first()->status, 'Pembayaran yang sah tetap lunas');
        $this->assertSame('dikembalikan', $ganda->fresh()->status);
        $this->assertSame([], $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin'));
    }

    public function test_dua_refund_menunggu_pada_satu_pesanan_wajib_menyebut_id_pembayaran(): void
    {
        [$budi] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1), ['status' => 'dikonfirmasi']);
        $this->lunasi($p, 'qris');
        $b2 = Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000, 'metode' => 'bank_transfer', 'order_id_midtrans' => $p->nomor.'-2',
            'status' => 'lunas', 'refund_cara' => 'transfer_admin', 'refund_selesai' => false]);
        $b3 = Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000, 'metode' => 'echannel', 'order_id_midtrans' => $p->nomor.'-3',
            'status' => 'lunas', 'refund_cara' => 'transfer_admin', 'refund_selesai' => false]);

        $this->assertCount(2, $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin'));

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai")->assertStatus(422)->assertJsonValidationErrors('id_pembayaran');
        $this->assertSame('lunas', $b2->fresh()->status, 'Tanpa id_pembayaran tidak ada yang berubah');

        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai", ['id_pembayaran' => $b3->id_pembayaran])->assertOk()->assertJsonPath('data.id_pembayaran', $b3->id_pembayaran);
        $this->assertSame(['lunas', 'dikembalikan'], [$b2->fresh()->status, $b3->fresh()->status]);

        // Sisa satu: boleh tanpa id_pembayaran
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai")->assertOk()->assertJsonPath('data.id_pembayaran', $b2->id_pembayaran);
        // id_pembayaran milik pesanan lain tidak dikenali
        $this->panggil('POST', self::API."/{$p->id_pemesanan}/refund-selesai", ['id_pembayaran' => 99999])->assertStatus(409);
    }

    public function test_pengajuan_yang_belum_disetujui_tidak_dihitung_sebagai_refund_menunggu(): void
    {
        $p = $this->pengajuan('bank_transfer');

        $tugas = $this->panggil('GET', '/api/v1/admin/dashboard')->json('data.tugas_admin');
        $this->assertSame(['pengajuan_pembatalan'], array_column($tugas, 'jenis'));
        $this->panggil('GET', self::API."/{$p->id_pemesanan}")->assertJsonPath('data.aksi.bisa_refund_selesai', false)->assertJsonPath('data.refund_menunggu', []);
    }
}
