<?php

namespace App\Console\Commands;

use App\Models\Pemesanan;
use App\Services\PemesananService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Spek API bagian 13: dikonfirmasi lewat tanggal kembali tanpa serah terima menjadi dibatalkan (tidak_diambil). */
class TutupPesananTidakDiambil extends Command
{
    protected $signature = 'pemesanan:tutup-tidak-diambil';

    protected $description = 'Tutup pesanan dikonfirmasi yang lewat tanggal kembali tanpa serah terima (pembayaran tetap lunas, tanpa refund)';

    public function handle(PemesananService $pemesanan): int
    {
        $ditutup = 0;

        Pemesanan::query()
            ->where('status', 'dikonfirmasi')->where('tgl_kembali', '<', now()->toDateString())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('serah_terima')
                ->whereColumn('serah_terima.id_pemesanan', 'pemesanan.id_pemesanan'))
            ->orderBy('id_pemesanan')
            ->pluck('id_pemesanan')
            ->each(function (int $id) use ($pemesanan, &$ditutup) {
                // Diperiksa ulang di dalam transaksi dengan kunci baris
                if ($pemesanan->tutupTidakDiambil($id)) {
                    $ditutup++;
                }
            });

        $this->info("Pesanan tidak diambil ditutup: {$ditutup}");

        return self::SUCCESS;
    }
}
