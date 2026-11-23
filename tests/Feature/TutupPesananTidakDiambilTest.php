<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Models\Pemesanan;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/** Tugas terjadwal: dikonfirmasi lewat tanggal kembali tanpa serah terima ditutup (Spek API bagian 13). */
class TutupPesananTidakDiambilTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    private function lunas(Pemesanan $p): Pemesanan
    {
        $p->pembayaran->first()->update(['status' => 'lunas', 'metode' => 'qris', 'tgl_bayar' => now()]);

        return $p;
    }

    public function test_terdaftar_di_scheduler_harian(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'pemesanan:tutup-tidak-diambil'));

        $this->assertNotNull($event, 'Perintah belum didaftarkan di routes/console.php');
        $this->assertSame('10 0 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_menutup_hanya_yang_dikonfirmasi_dan_lewat_tanggal_kembali_tanpa_serah_terima(): void
    {
        [$p] = $this->buatPelanggan();
        $t = $this->buatTipe(10);
        $kemarin = now()->subDay()->toDateString();
        $lampau = now()->subDays(3)->toDateString();

        $lewat = $this->lunas($this->buatPesanan($p, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => $lampau, 'durasi' => 2, 'tgl_kembali' => $kemarin]));
        $hariKembali = $this->lunas($this->buatPesanan($p, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => $kemarin, 'durasi' => 2, 'tgl_kembali' => now()->toDateString()]));
        $akanDatang = $this->lunas($this->buatPesanan($p, $t, ['status' => 'dikonfirmasi']));
        $berjalan = $this->buatPesanan($p, $t, ['status' => 'berjalan', 'tgl_mulai' => $lampau, 'tgl_kembali' => $kemarin]);
        $pengajuan = $this->buatPesanan($p, $t, ['status' => 'pengajuan_pembatalan', 'tgl_mulai' => $lampau, 'tgl_kembali' => $kemarin]);
        $menunggu = $this->buatPesanan($p, $t, ['tgl_mulai' => $lampau, 'tgl_kembali' => $kemarin]);

        $this->artisan('pemesanan:tutup-tidak-diambil')->expectsOutput('Pesanan tidak diambil ditutup: 1')->assertSuccessful();

        $lewat->refresh();
        $this->assertSame(['dibatalkan', 'tidak_diambil'], [$lewat->status, $lewat->alasan_pembatalan]);
        $bayar = $lewat->pembayaran->first()->fresh();
        $this->assertSame(['lunas', null], [$bayar->status, $bayar->refund_cara], 'Pembayaran tetap lunas, tanpa refund');
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $lewat->id_pemesanan, 'pelaku' => 'sistem', 'id_akun' => null,
            'aksi' => 'Pesanan ditutup: unit tidak diambil sampai tanggal kembali (pembayaran tetap lunas, tanpa refund)']);

        foreach ([$hariKembali, $akanDatang] as $lain) {
            $this->assertSame('dikonfirmasi', $lain->fresh()->status);
        }
        $this->assertSame('berjalan', $berjalan->fresh()->status);
        $this->assertSame('pengajuan_pembatalan', $pengajuan->fresh()->status);
        $this->assertSame('menunggu_pembayaran', $menunggu->fresh()->status);

        // Idempoten
        $this->artisan('pemesanan:tutup-tidak-diambil')->expectsOutput('Pesanan tidak diambil ditutup: 0')->assertSuccessful();
        $this->assertSame(1, Pemesanan::where('alasan_pembatalan', 'tidak_diambil')->count());
        $this->assertSame(0, Pembayaran::where('status', 'dikembalikan')->count());
    }

    public function test_pesanan_yang_sudah_punya_serah_terima_tidak_ditutup(): void
    {
        [$p] = $this->buatPelanggan();
        $t = $this->buatTipe(1);
        $unit = $t->kendaraan()->first();
        $pesanan = $this->buatPesanan($p, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => now()->subDays(3)->toDateString(), 'tgl_kembali' => now()->subDay()->toDateString()]);
        DB::table('serah_terima')->insert(['id_pemesanan' => $pesanan->id_pemesanan, 'id_kendaraan' => $unit->id_kendaraan,
            'tgl_serah' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->artisan('pemesanan:tutup-tidak-diambil')->expectsOutput('Pesanan tidak diambil ditutup: 0')->assertSuccessful();

        $this->assertSame('dikonfirmasi', $pesanan->fresh()->status);
    }

    public function test_tanggal_kembali_dihitung_menurut_tanggal_jakarta(): void
    {
        // 13 Nov 18:30 UTC = 14 Nov 01:30 WIB. Kembali 13 Nov: sudah lewat menurut WIB, belum menurut UTC.
        $this->travelTo(Carbon::parse('2026-11-13 18:30:00', 'UTC'));
        [$p] = $this->buatPelanggan();
        $t = $this->buatTipe(2);
        $lewatWib = $this->buatPesanan($p, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-12', 'tgl_kembali' => '2026-11-13']);
        $hariIniWib = $this->buatPesanan($p, $t, ['status' => 'dikonfirmasi', 'tgl_mulai' => '2026-11-13', 'tgl_kembali' => '2026-11-14']);

        $this->artisan('pemesanan:tutup-tidak-diambil')->expectsOutput('Pesanan tidak diambil ditutup: 1')->assertSuccessful();

        $this->assertSame('dibatalkan', $lewatWib->fresh()->status);
        $this->assertSame('dikonfirmasi', $hariIniWib->fresh()->status);
    }
}
