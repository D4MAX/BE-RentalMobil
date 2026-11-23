<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** POST /pemesanan/{id}/ajukan-pembatalan: dikonfirmasi, paling lambat H-1 (tanggal WIB). */
class AjukanPembatalanTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private function ajukan(int $id, string $token, array $isi)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson("/api/v1/pemesanan/{$id}/ajukan-pembatalan", $isi);
    }

    private function dikonfirmasi($pelanggan, $tipe, string $metode = 'qris', array $ubah = [])
    {
        $p = $this->buatPesanan($pelanggan, $tipe, ['status' => 'dikonfirmasi'] + $ubah);
        $p->pembayaran->first()->update(['status' => 'lunas', 'metode' => $metode, 'tgl_bayar' => now()]);

        return $p;
    }

    public function test_qris_cukup_alasan_status_sebelumnya_disimpan_dan_tanggal_tetap_terblokir(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $p = $this->dikonfirmasi($budi, $t);

        $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'Jadwal berubah'])->assertOk()->assertJsonPath('data.status', 'pengajuan_pembatalan');

        $p->refresh();
        $this->assertSame(['pengajuan_pembatalan', 'dikonfirmasi', 'Jadwal berubah'], [$p->status, $p->status_sebelumnya, $p->alasan_pengajuan]);
        $this->assertNull($p->alasan_pembatalan, 'alasan_pembatalan hanya untuk kode sistem');
        $bayar = Pembayaran::sole();
        $this->assertSame(['lunas', null, null], [$bayar->status, $bayar->refund_cara, $bayar->no_rekening], 'refund_cara baru diisi saat disetujui');
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'pelaku' => 'pelanggan', 'aksi' => 'Pelanggan mengajukan pembatalan']);

        // Tanggal tetap terblokir: tipe satu unit itu tidak muncul di katalog untuk tanggal yang sama
        $mulai = $p->tgl_mulai->toDateString();
        $this->getJson("/api/v1/katalog/tipe?tgl_mulai={$mulai}&durasi=2")->assertJsonCount(0, 'data');
    }

    public function test_virtual_account_wajib_rekening_dan_tersimpan_di_pembayaran(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->dikonfirmasi($budi, $this->buatTipe(1), 'bank_transfer');

        $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'Batal'])->assertStatus(422)->assertJsonPath('code', 'VALIDASI_GAGAL')
            ->assertJsonValidationErrors(['bank', 'no_rekening', 'nama_rekening']);
        $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'Batal', 'bank' => 'BCA'])->assertStatus(422)
            ->assertJsonValidationErrors(['no_rekening', 'nama_rekening']);
        $this->assertSame('dikonfirmasi', $p->fresh()->status, 'Pengajuan yang ditolak tidak mengubah apa pun');

        $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'Batal', 'bank' => 'BCA', 'no_rekening' => '1234567890', 'nama_rekening' => 'Budi Santoso'])->assertOk();

        $bayar = Pembayaran::sole();
        $this->assertSame(['BCA', '1234567890', 'Budi Santoso', null, false],
            [$bayar->bank, $bayar->no_rekening, $bayar->nama_rekening, $bayar->refund_cara, $bayar->refund_selesai]);
        $this->assertSame('pengajuan_pembatalan', $p->fresh()->status);
    }

    public function test_rekening_wajib_hanya_untuk_va_dan_gerai(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(8);

        // Gerai (cstore) dan VA Mandiri (echannel) wajib rekening
        foreach (['cstore', 'echannel'] as $channel) {
            $p = $this->dikonfirmasi($budi, $t, $channel);
            $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'Batal'])->assertStatus(422)->assertJsonValidationErrors(['bank', 'no_rekening', 'nama_rekening']);
        }

        // QRIS, e-wallet, kartu, dan channel yang tak dikenal tidak wajib rekening; cara refund ditentukan saat disetujui
        foreach (['qris', 'gopay', 'credit_card', 'channel_baru'] as $channel) {
            $p = $this->dikonfirmasi($budi, $t, $channel);
            $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'Batal'])->assertOk();
            $this->assertNull($p->pembayaran->first()->fresh()->refund_cara, $channel);
            $this->assertNull($p->pembayaran->first()->fresh()->no_rekening);
        }
    }

    public function test_alasan_wajib(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $p = $this->dikonfirmasi($budi, $this->buatTipe(1));

        $this->ajukan($p->id_pemesanan, $token, [])->assertStatus(422)->assertJsonValidationErrors('alasan');
        $this->ajukan($p->id_pemesanan, $token, ['alasan' => str_repeat('x', 256)])->assertStatus(422)->assertJsonValidationErrors('alasan');
    }

    public function test_batas_h_minus_1_boleh_sampai_hari_ini_sama_dengan_tgl_mulai_minus_1(): void
    {
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(5);

        // Besok mulai: hari ini masih H-1, boleh
        $besok = $this->dikonfirmasi($budi, $t, 'qris', ['tgl_mulai' => now()->addDay()->toDateString()]);
        $this->ajukan($besok->id_pemesanan, $token, ['alasan' => 'x'])->assertOk();

        // Mulai hari ini: sudah H-0, ditolak
        $hariIni = $this->dikonfirmasi($budi, $t, 'qris', ['tgl_mulai' => now()->toDateString()]);
        $this->ajukan($hariIni->id_pemesanan, $token, ['alasan' => 'x'])->assertStatus(409)
            ->assertJsonPath('code', 'LEWAT_BATAS_H1')->assertJsonPath('message', 'Pesanan tidak bisa dibatalkan');
        $this->assertSame('dikonfirmasi', $hariIni->fresh()->status);
    }

    public function test_batas_h_minus_1_memakai_tanggal_jakarta_bukan_utc(): void
    {
        // 13 Nov 18:30 UTC = 14 Nov 01:30 WIB
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'UTC'));
        [$budi, $token] = $this->buatPelanggan();
        $t = $this->buatTipe(5);

        $h1 = $this->dikonfirmasi($budi, $t, 'qris', ['tgl_mulai' => '2026-11-15']);   // hari ini WIB 14: H-1
        $h0 = $this->dikonfirmasi($budi, $t, 'qris', ['tgl_mulai' => '2026-11-14']);   // hari ini WIB 14: H-0 (UTC masih H-1)

        $this->ajukan($h1->id_pemesanan, $token, ['alasan' => 'x'])->assertOk();
        $this->ajukan($h0->id_pemesanan, $token, ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('code', 'LEWAT_BATAS_H1');
    }

    public function test_status_lain_orang_lain_dan_pengajuan_ganda(): void
    {
        [$budi, $token] = $this->buatPelanggan('budi@mail.test');
        [, $tokenSari] = $this->buatPelanggan('sari@mail.test');
        $t = $this->buatTipe(8);

        foreach (['menunggu_pembayaran', 'berjalan', 'selesai', 'dibatalkan', 'pengajuan_pembatalan'] as $status) {
            $p = $this->buatPesanan($budi, $t, ['status' => $status]);
            $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');
        }

        $p = $this->dikonfirmasi($budi, $t);
        $this->ajukan($p->id_pemesanan, $tokenSari, ['alasan' => 'x'])->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'x'])->assertOk();
        $this->ajukan($p->id_pemesanan, $token, ['alasan' => 'x'])->assertStatus(409)->assertJsonPath('code', 'STATUS_TIDAK_VALID');

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->postJson("/api/v1/pemesanan/{$p->id_pemesanan}/ajukan-pembatalan", ['alasan' => 'x'])->assertUnauthorized();
    }
}
