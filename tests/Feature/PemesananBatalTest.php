<?php

namespace Tests\Feature;

use App\Models\LogAktivitas;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Services\Pembayaran\PembayaranGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** POST /pemesanan/{id}/batalkan: hanya menunggu_pembayaran milik sendiri (Spek API bagian 6). */
class PemesananBatalTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private function batalkan(int $id, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson("/api/v1/pemesanan/{$id}/batalkan");
    }

    public function test_membatalkan_pesanan_menunggu_melepas_unit_dan_menggagalkan_pembayaran(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $p = $this->buatPesanan($budi, $t);
        $dua = Pembayaran::create(['id_pemesanan' => $p->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000,
            'order_id_midtrans' => $p->nomor.'-2', 'status' => 'menunggu']);

        $this->batalkan($p->id_pemesanan, $token)->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        $p->refresh();
        $this->assertSame(['dibatalkan', 'dibatalkan_pelanggan'], [$p->status, $p->alasan_pembatalan]);
        $this->assertSame(['gagal', 'gagal'], Pembayaran::orderBy('id_pembayaran')->pluck('status')->all());
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'pelaku' => 'pelanggan',
            'id_akun' => $budi->id_akun, 'aksi' => 'Pelanggan membatalkan pesanan']);
        // Setiap transaksi Midtrans yang menunggu dimatikan
        $this->assertSame([$p->nomor.'-1', $p->nomor.'-2'], app(PembayaranGateway::class)->batalDicatat);

        // Unit lepas: pelanggan lain bisa memesannya, dan pembatal boleh memesan lagi
        [, $tokenLain] = $this->buatPelanggan('lain@mail.test');
        $isi = ['id_tipe' => $t->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual', 'tgl_mulai' => $p->tgl_mulai->toDateString(),
            'durasi' => 2, 'jam_serah' => '09:00', 'metode_ambil' => 'ambil_di_kantor', 'setuju_syarat' => true, 'setuju_syarat_jaminan' => true];
        $this->withToken($tokenLain)->postJson('/api/v1/pemesanan', $isi)->assertCreated();
    }

    public function test_kegagalan_membatalkan_di_midtrans_tidak_menggagalkan_pembatalan(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1));
        app(PembayaranGateway::class)->gagalBatal = true;

        $this->batalkan($p->id_pemesanan, $token)->assertOk()->assertJsonPath('data.status', 'dibatalkan');

        $this->assertSame('dibatalkan', $p->fresh()->status);
        $this->assertSame('gagal', Pembayaran::sole()->status);
    }

    public function test_status_selain_menunggu_pembayaran_ditolak_409(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(8);

        foreach (['dikonfirmasi', 'berjalan', 'selesai', 'pengajuan_pembatalan', 'dibatalkan'] as $status) {
            $p = $this->buatPesanan($budi, $t, ['status' => $status]);
            $this->batalkan($p->id_pemesanan, $token)->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
            $this->assertSame($status, $p->fresh()->status, "Status {$status} tidak boleh berubah");
        }
        $this->assertSame([], app(PembayaranGateway::class)->batalDicatat);
        $this->assertSame(0, LogAktivitas::count());
    }

    public function test_milik_orang_lain_tidak_ditemukan_dan_tanpa_login_ditolak(): void
    {
        [$budi] = $this->buatPelanggan('budi@mail.test');
        [, $tokenSari] = $this->buatPelanggan('sari@mail.test');
        $p = $this->buatPesanan($budi, $this->buatTipe(1));

        $this->batalkan($p->id_pemesanan, $tokenSari)->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->batalkan(9999, $tokenSari)->assertNotFound();
        $this->assertSame('menunggu_pembayaran', $p->fresh()->status);

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson("/api/v1/pemesanan/{$p->id_pemesanan}/batalkan")->assertUnauthorized();
    }

    public function test_dibatalkan_dua_kali_kedua_409(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1));

        $this->batalkan($p->id_pemesanan, $token)->assertOk();
        $this->batalkan($p->id_pemesanan, $token)->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        $this->assertSame(1, LogAktivitas::count());
    }

    public function test_pembayaran_yang_lolos_setelah_dibatalkan_direfund_oleh_webhook(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(1));
        $order = $p->nomor.'-1';
        $this->batalkan($p->id_pemesanan, $token)->assertOk();

        $gross = '600000.00';
        $this->postJson('/api/v1/webhook/midtrans', [
            'order_id' => $order, 'status_code' => '200', 'gross_amount' => $gross, 'transaction_status' => 'settlement', 'payment_type' => 'qris',
            'signature_key' => hash('sha512', $order.'200'.$gross.config('midtrans.server_key')),
        ])->assertOk();

        $this->assertSame('dibatalkan', $p->fresh()->status, 'Tidak dihidupkan: dibatalkan pelanggan, bukan kedaluwarsa');
        $this->assertSame('dikembalikan', Pembayaran::sole()->status);
        $this->assertCount(1, app(PembayaranGateway::class)->refundDicatat);
    }
}
