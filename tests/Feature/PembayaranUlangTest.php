<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Services\Pembayaran\PembayaranGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** POST /pemesanan/{id}/pembayaran: snap token baru (tombol "Bayar sekarang"). */
class PembayaranUlangTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private function bayar(int $id, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson("/api/v1/pemesanan/{$id}/pembayaran");
    }

    public function test_menerbitkan_token_baru_dengan_order_id_berikutnya_dan_masa_berlaku_sisa_waktu(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe(), ['batas_pembayaran' => now()->addMinutes(20)]);

        $r = $this->bayar($p->id_pemesanan, $token)->assertOk()
            ->assertJsonPath('data.snap_token', 'snap-palsu-'.$p->nomor.'-2')
            ->assertJsonPath('data.batas_pembayaran', $p->fresh()->batas_pembayaran->toIso8601String());
        $this->assertStringEndsWith('+07:00', $r->json('data.batas_pembayaran'));

        $this->assertSame(
            [$p->nomor.'-1', $p->nomor.'-2'],
            Pembayaran::where('id_pemesanan', $p->id_pemesanan)->orderBy('id_pembayaran')->pluck('order_id_midtrans')->all()
        );
        $this->assertSame(['menunggu', 'menunggu'], Pembayaran::pluck('status')->all());
        $this->assertSame(20, app(PembayaranGateway::class)->transaksiDibuat[0]['kedaluwarsaMenit']);

        // Tombol ditekan lagi: percobaan ketiga
        $this->bayar($p->id_pemesanan, $token)->assertOk()->assertJsonPath('data.snap_token', 'snap-palsu-'.$p->nomor.'-3');
    }

    public function test_lewat_batas_atau_sudah_dibatalkan_karena_kedaluwarsa_ditolak(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(3);

        $lewat = $this->buatPesanan($budi, $t, ['batas_pembayaran' => now()->subMinute()]);
        $this->bayar($lewat->id_pemesanan, $token)->assertStatus(409)->assertJsonPath('code', 'PEMBAYARAN_KEDALUWARSA');

        $batal = $this->buatPesanan($budi, $t, ['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa']);
        $this->bayar($batal->id_pemesanan, $token)->assertStatus(409)->assertJsonPath('code', 'PEMBAYARAN_KEDALUWARSA');

        $this->assertSame(2, Pembayaran::count(), 'Percobaan yang ditolak tidak boleh membuat baris pembayaran');
    }

    public function test_status_lain_orang_lain_dan_tanpa_login(): void
    {
        [$budi, $token] = $this->buatPelanggan('budi@mail.test');
        [, $tokenSari] = $this->buatPelanggan('sari@mail.test');
        $t = $this->buatTipe(3);

        $konfirmasi = $this->buatPesanan($budi, $t, ['status' => 'dikonfirmasi']);
        $this->bayar($konfirmasi->id_pemesanan, $token)->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $punyaBudi = $this->buatPesanan($budi, $t);
        $this->bayar($punyaBudi->id_pemesanan, $tokenSari)->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->bayar(9999, $token)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders(); // buang token dari permintaan sebelumnya
        $this->postJson("/api/v1/pemesanan/{$punyaBudi->id_pemesanan}/pembayaran")->assertUnauthorized();
    }

    public function test_gateway_gagal_menandai_percobaan_gagal_tetapi_pesanan_tetap_menunggu(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->buatPesanan($budi, $this->buatTipe());
        app(PembayaranGateway::class)->gagalBuat = true;

        $this->bayar($p->id_pemesanan, $token)->assertStatus(502)->assertJsonPath('code', 'PEMBAYARAN_GAGAL');

        $this->assertSame('menunggu_pembayaran', $p->fresh()->status);
        $this->assertSame(['menunggu', 'gagal'], Pembayaran::orderBy('id_pembayaran')->pluck('status')->all());

        app(PembayaranGateway::class)->gagalBuat = false;
        $this->bayar($p->id_pemesanan, $token)->assertOk()->assertJsonPath('data.snap_token', 'snap-palsu-'.$p->nomor.'-3');
    }
}
