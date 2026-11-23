<?php

namespace App\Console\Commands;

use App\Models\Akun;
use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Merapikan data sebelum demo: migrate:fresh --seed lalu hapus berkas upload yatim (database kosong, termasuk tempat sampah tipe/unit, jadi semua berkas di
 * folder upload aplikasi ini tidak lagi dirujuk). Hanya untuk database rental_mobil di luar production, dengan konfirmasi.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Lewati konfirmasi}';

    protected $description = 'Reset database demo (migrate:fresh --seed) dan hapus berkas upload yatim. Hanya database rental_mobil, tidak di production';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Ditolak: demo:reset tidak boleh dijalankan di APP_ENV=production.');

            return self::FAILURE;
        }

        $izin = (string) config('rental.demo_reset_database');
        $db = (string) DB::connection()->getDatabaseName();
        if (in_array($db, config('rental.demo_reset_terlarang'), true)) {
            $this->error("Ditolak: database \"{$db}\" milik proyek lain dan tidak boleh disentuh.");

            return self::FAILURE;
        }
        if ($izin === '' || $db !== $izin) {
            $this->error("Ditolak: database aktif \"{$db}\", sedangkan perintah ini hanya boleh untuk \"{$izin}\".");

            return self::FAILURE;
        }

        $folder = config('rental.folder_upload');
        $this->line('<info>Sebelum reset</info>');
        $this->line("  Database : {$db}");
        $this->line('  Tabel    : '.count(Schema::getTables($db)).' (seluruhnya akan dihapus lalu dibuat ulang)');
        $this->line('  Berkas   : akan dihapus dari '.$this->daftarFolder($folder));

        if (! $this->option('force') && ! $this->confirm("Semua data di database \"{$db}\" akan HILANG. Lanjutkan?", false)) {
            $this->warn('Dibatalkan, tidak ada yang diubah.');

            return self::FAILURE;
        }

        $kode = $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        if ($kode !== self::SUCCESS) {
            $this->error('migrate:fresh gagal; berkas upload tidak disentuh.');

            return self::FAILURE;
        }

        $berkas = $this->bersihkanBerkas($folder);

        $this->newLine();
        $this->line('<info>Setelah reset</info>');
        $this->line('  Akun seed : '.Akun::count().' ('.Akun::pluck('email')->implode(', ').')');
        $this->line('  Tipe      : '.TipeKendaraan::count());
        $this->line('  Unit      : '.Kendaraan::count());
        // migrate:fresh membuang seluruh tabel, jadi tipe dan unit di tempat sampah ikut hilang (fotonya ikut dibersihkan di atas)
        $this->line('  Sampah    : '.TipeKendaraan::onlyTrashed()->count().' tipe, '.Kendaraan::onlyTrashed()->count().' unit');
        $this->line("  Berkas yatim dihapus : {$berkas}");

        return self::SUCCESS;
    }

    /** Hapus hanya folder yang terdaftar di config, per disk. @param  array<string,list<string>>  $folder */
    private function bersihkanBerkas(array $folder): int
    {
        $jumlah = 0;
        foreach ($folder as $disk => $daftar) {
            $penyimpanan = Storage::disk($disk);
            foreach ($daftar as $nama) {
                $jumlah += count($penyimpanan->allFiles($nama));
                $penyimpanan->deleteDirectory($nama);
            }
        }

        return $jumlah;
    }

    /** @param  array<string,list<string>>  $folder */
    private function daftarFolder(array $folder): string
    {
        $bagian = [];
        foreach ($folder as $disk => $daftar) {
            $bagian[] = "disk {$disk}: ".implode(', ', $daftar);
        }

        return implode('; ', $bagian);
    }
}
