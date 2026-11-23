<?php

namespace Tests\Feature;

use App\Models\Pembayaran;
use App\Services\Pembayaran\PembayaranGateway;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuatDataPemesanan;
use Tests\TestCase;

/**
 * Refund Midtrans diputuskan di dalam transaksi, tetapi dipanggil sesudah commit. Bila proses mati di antaranya, baris
 * tertinggal lunas + refund_cara midtrans + belum selesai, dan tidak ada tampilan yang menunjukkannya. Tugas terjadwal
 * mengulang refund itu (refund_key tetap per order, jadi aman diulang).
 */
class UlangiRefundTertundaTest extends TestCase
{
    use BuatDataPemesanan, RefreshDatabase;

    /** Pembayaran lunas yang refund Midtrans-nya tidak pernah dipanggil, terakhir berubah $menitLalu menit yang lalu. */
    private function refundTertunda(int $menitLalu, array $ubah = []): Pembayaran
    {
        [$p] = $this->buatPelanggan('p'.uniqid().'@mail.test');
        $pesanan = $this->buatPesanan($p, $this->buatTipe(1, 'T'.uniqid()), ['status' => 'dibatalkan', 'alasan_pembatalan' => 'pengajuan_disetujui']);
        $bayar = $pesanan->pembayaran->first();
        $bayar->update($ubah + ['status' => 'lunas', 'metode' => 'qris', 'tgl_bayar' => now(), 'refund_cara' => 'midtrans', 'refund_selesai' => false]);
        Pembayaran::whereKey($bayar->id_pembayaran)->update(['updated_at' => now()->subMinutes($menitLalu)]);

        return $bayar->fresh();
    }

    public function test_terdaftar_di_scheduler_tiap_10_menit(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'refund:ulangi-tertunda'));

        $this->assertNotNull($event, 'Perintah belum didaftarkan di routes/console.php');
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_mengulang_refund_yang_tertahan_lebih_dari_10_menit(): void
    {
        $bayar = $this->refundTertunda(15);

        $this->artisan('refund:ulangi-tertunda')->expectsOutput('Refund tertunda diulang: 1 (berhasil 1)')->assertSuccessful();

        $bayar->refresh();
        $this->assertSame(['dikembalikan', true], [$bayar->status, $bayar->refund_selesai]);
        $this->assertSame([['orderId' => $bayar->order_id_midtrans, 'jumlah' => (int) $bayar->jumlah, 'alasan' => 'Percobaan ulang refund yang tertunda']],
            app(PembayaranGateway::class)->refundDicatat);

        // Dijalankan lagi: sudah selesai, tidak di-refund dua kali
        $this->artisan('refund:ulangi-tertunda')->expectsOutput('Refund tertunda diulang: 0 (berhasil 0)')->assertSuccessful();
        $this->assertCount(1, app(PembayaranGateway::class)->refundDicatat);
    }

    public function test_tidak_menyentuh_refund_yang_baru_saja_dibuat_karena_mungkin_sedang_diproses(): void
    {
        $this->refundTertunda(2);

        $this->artisan('refund:ulangi-tertunda')->expectsOutput('Refund tertunda diulang: 0 (berhasil 0)')->assertSuccessful();

        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat);
    }

    public function test_tidak_menyentuh_transfer_admin_yang_sudah_selesai_atau_bukan_pembayaran_lunas(): void
    {
        $this->refundTertunda(60, ['refund_cara' => 'transfer_admin']);
        $this->refundTertunda(60, ['refund_selesai' => true, 'status' => 'dikembalikan']);
        $this->refundTertunda(60, ['status' => 'gagal']);
        $this->refundTertunda(60, ['refund_cara' => null]);

        $this->artisan('refund:ulangi-tertunda')->expectsOutput('Refund tertunda diulang: 0 (berhasil 0)')->assertSuccessful();

        $this->assertSame([], app(PembayaranGateway::class)->refundDicatat);
    }

    public function test_bila_midtrans_menolak_dialihkan_ke_transfer_admin_agar_muncul_di_dashboard_admin(): void
    {
        $bayar = $this->refundTertunda(30);
        app(PembayaranGateway::class)->gagalRefund = true;

        $this->artisan('refund:ulangi-tertunda')->expectsOutput('Refund tertunda diulang: 1 (berhasil 0)')->assertSuccessful();

        $bayar->refresh();
        $this->assertSame(['lunas', 'transfer_admin', false], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai]);
    }
}
