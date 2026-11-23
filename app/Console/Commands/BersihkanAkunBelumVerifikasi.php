<?php

namespace App\Console\Commands;

use App\Models\Akun;
use Illuminate\Console\Command;

/**
 * PRD: akun user yang tidak diverifikasi dihapus setelah 30 hari, sehingga emailnya bebas dipakai mendaftar lagi
 * (AuthService::register hanya menggantikan akun belum terverifikasi yang umurnya kurang dari 30 hari). Pelanggan dan
 * token ikut terhapus lewat cascade. Akun yang sudah punya pesanan tidak disentuh. Idempoten.
 */
class BersihkanAkunBelumVerifikasi extends Command
{
    protected $signature = 'akun:bersihkan-belum-verifikasi';

    protected $description = 'Hapus akun user yang belum terverifikasi lebih dari 30 hari';

    public function handle(): int
    {
        $dihapus = Akun::query()
            ->where('role', 'user')->whereNull('email_terverifikasi_at')
            ->where('created_at', '<=', now()->subDays(30))
            ->whereDoesntHave('pelanggan.pemesanan')
            ->delete();

        $this->info("Akun belum terverifikasi dihapus: {$dihapus}");

        return self::SUCCESS;
    }
}
