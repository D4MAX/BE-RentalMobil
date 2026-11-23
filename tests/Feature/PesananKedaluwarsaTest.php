<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Models\Pemesanan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** Tugas terjadwal tiap menit: pesanan menunggu pembayaran yang lewat batas dibatalkan (Spek API bagian 7). */
class PesananKedaluwarsaTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    public function test_terdaftar_di_scheduler_tiap_menit(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'pemesanan:batalkan-kedaluwarsa'));

        $this->assertNotNull($event, 'Perintah belum didaftarkan di routes/console.php');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_hanya_yang_lewat_batas_dibatalkan_dan_unit_dilepas(): void
    {
        [$p] = $this->buatPelanggan('a@mail.test');
        [$q] = $this->buatPelanggan('b@mail.test');
        [$r] = $this->buatPelanggan('c@mail.test');
        $t = $this->buatTipe(5);

        $lewat = $this->buatPesanan($p, $t, ['batas_pembayaran' => now()->subMinute()]);
        $masihAda = $this->buatPesanan($q, $t, ['batas_pembayaran' => now()->addMinutes(10)]);
        $sudahBayar = $this->buatPesanan($r, $t, ['status' => 'dikonfirmasi', 'batas_pembayaran' => now()->subHour()]);
        $dua = Pembayaran::create(['id_pemesanan' => $lewat->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000,
            'order_id_midtrans' => $lewat->nomor.'-2', 'status' => 'menunggu']);

        $this->artisan('pemesanan:batalkan-kedaluwarsa')->expectsOutput('Pesanan kedaluwarsa dibatalkan: 1')->assertSuccessful();

        $lewat->refresh();
        $this->assertSame(['dibatalkan', 'kedaluwarsa'], [$lewat->status, $lewat->alasan_pembatalan]);
        $this->assertSame(['gagal', 'gagal'], Pembayaran::where('id_pemesanan', $lewat->id_pemesanan)->orderBy('id_pembayaran')->pluck('status')->all());
        $this->assertSame('menunggu_pembayaran', $masihAda->fresh()->status);
        $this->assertSame('dikonfirmasi', $sudahBayar->fresh()->status);
        $this->assertSame('menunggu', $masihAda->pembayaran->first()->fresh()->status);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $lewat->id_pemesanan, 'pelaku' => 'sistem', 'id_akun' => null,
            'aksi' => 'Pembayaran tidak diterima dalam 1 jam, pesanan dibatalkan otomatis']);

        // Dijalankan lagi: tidak ada yang berubah (idempoten)
        $this->artisan('pemesanan:batalkan-kedaluwarsa')->expectsOutput('Pesanan kedaluwarsa dibatalkan: 0')->assertSuccessful();
        $this->assertSame(1, Pemesanan::where('status', 'dibatalkan')->count());
        $this->assertDatabaseCount('log_aktivitas', 1);
    }

    public function test_unit_terakhir_bisa_dipesan_lagi_setelah_pesanan_kedaluwarsa_dibatalkan(): void
    {
        [$p] = $this->buatPelanggan('a@mail.test');
        [, $tokenB] = $this->buatPelanggan('b@mail.test');
        $t = $this->buatTipe(1);
        $a = $this->buatPesanan($p, $t, ['batas_pembayaran' => now()->subSeconds(30)]);

        // Sebelum scheduler jalan pun unit sudah dihitung bebas (batas lewat); sesudahnya status pun dibatalkan
        $this->artisan('pemesanan:batalkan-kedaluwarsa')->assertSuccessful();

        $this->withToken($tokenB)->postJson('/api/v1/pemesanan', [
            'id_tipe' => $t->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual', 'tgl_mulai' => $a->tgl_mulai->toDateString(),
            'durasi' => 2, 'jam_serah' => '09:00', 'metode_ambil' => 'ambil_di_kantor', 'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ])->assertCreated();
    }
}
