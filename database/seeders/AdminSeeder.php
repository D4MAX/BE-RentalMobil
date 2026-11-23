<?php

namespace Database\Seeders;

use App\Models\Akun;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /** Nilai bawaan config/rental.php; tertulis di README, jadi tidak boleh dipakai di production. */
    public const PASSWORD_BAWAAN = 'Admin12345';

    public function run(): void
    {
        // Hanya saat admin belum ada: deploy ulang dengan --seed tidak boleh gagal bila ADMIN_PASSWORD sudah dihapus dari .env
        if (app()->isProduction() && config('rental.admin_password') === self::PASSWORD_BAWAAN
            && ! Akun::where('email', config('rental.admin_email'))->exists()) {
            throw new RuntimeException('Admin tidak dibuat: isi ADMIN_PASSWORD di .env production dengan kata sandi sendiri (nilai bawaan diketahui publik).');
        }

        Akun::firstOrCreate(
            ['email' => config('rental.admin_email')],
            [
                'nama' => config('rental.admin_nama'),
                'password' => config('rental.admin_password'), // sementara, diganti saat login pertama
                'role' => 'admin',
                'aktif' => true,
                'email_terverifikasi_at' => now(),
                'wajib_ganti_password' => true,
            ]
        );
    }
}
