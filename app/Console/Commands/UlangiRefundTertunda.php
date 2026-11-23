<?php

namespace App\Console\Commands;

use App\Models\Pembayaran;
use App\Services\RefundService;
use Illuminate\Console\Command;

/**
 * Refund Midtrans diputuskan di dalam transaksi tetapi dipanggil sesudah commit (RefundService). Bila proses mati di antara
 * keduanya, pembayaran tertinggal lunas dengan refund_cara midtrans dan refund_selesai false, dan tidak ada yang mengulang.
 * Perintah ini mengulangnya. Aman diulang: refund_key tetap per order. Yang baru berubah kurang dari 10 menit dilewati
 * karena mungkin masih diproses permintaan lain. Bila Midtrans menolak, RefundService mengalihkannya ke transfer_admin
 * sehingga muncul di tugas admin.
 */
class UlangiRefundTertunda extends Command
{
    protected $signature = 'refund:ulangi-tertunda';

    protected $description = 'Ulangi refund Midtrans yang tertunda lebih dari 10 menit';

    public function handle(RefundService $refund): int
    {
        $diulang = $berhasil = 0;

        Pembayaran::query()
            ->where('jenis', 'sewa')->where('status', 'lunas')
            ->where('refund_cara', 'midtrans')->where('refund_selesai', false)
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->orderBy('id_pembayaran')
            ->get(['id_pembayaran', 'order_id_midtrans', 'jumlah'])
            ->each(function (Pembayaran $b) use ($refund, &$diulang, &$berhasil) {
                $diulang++;
                $refund->lewatMidtrans($b->id_pembayaran, $b->order_id_midtrans, (int) $b->jumlah, 'Percobaan ulang refund yang tertunda') && $berhasil++;
            });

        $this->info("Refund tertunda diulang: {$diulang} (berhasil {$berhasil})");

        return self::SUCCESS;
    }
}
