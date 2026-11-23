<?php

namespace App\Console\Commands;

use App\Models\Pemesanan;
use App\Services\PemesananService;
use Illuminate\Console\Command;

/** Cadangan bila notifikasi expire Midtrans terlambat (Spek API bagian 7). Dijadwalkan tiap menit. */
class BatalkanPesananKedaluwarsa extends Command
{
    protected $signature = 'pemesanan:batalkan-kedaluwarsa';

    protected $description = 'Batalkan pesanan menunggu pembayaran yang lewat batas 1 jam dan lepas unitnya';

    public function handle(PemesananService $pemesanan): int
    {
        $dibatalkan = 0;

        Pemesanan::query()
            ->where('status', 'menunggu_pembayaran')->where('batas_pembayaran', '<=', now())
            ->orderBy('id_pemesanan')
            ->pluck('id_pemesanan')
            ->each(function (int $id) use ($pemesanan, &$dibatalkan) {
                // Diperiksa ulang di dalam transaksi: pembayaran bisa masuk tepat sebelum ini
                if ($pemesanan->batalkanOtomatis($id, 'kedaluwarsa', 'Pembayaran tidak diterima dalam 1 jam, pesanan dibatalkan otomatis')) {
                    $dibatalkan++;
                }
            });

        $this->info("Pesanan kedaluwarsa dibatalkan: {$dibatalkan}");

        return self::SUCCESS;
    }
}
