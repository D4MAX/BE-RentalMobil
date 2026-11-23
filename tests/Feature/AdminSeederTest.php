<?php

namespace Tests\Feature;

use App\Models\Akun;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** Kata sandi bawaan (tertulis di README) tidak boleh membuat akun admin di production. */
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_di_luar_production_kata_sandi_bawaan_dipakai_dan_wajib_diganti(): void
    {
        $this->seed(AdminSeeder::class);

        $admin = Akun::where('role', 'admin')->first();
        $this->assertTrue($admin->wajib_ganti_password);
        $this->assertSame(1, Akun::count());
    }

    public function test_production_tanpa_admin_password_ditolak_dan_tidak_membuat_akun(): void
    {
        $this->app['env'] = 'production';
        config(['rental.admin_password' => 'Admin12345']); // nilai bawaan: ADMIN_PASSWORD tidak diisi

        try {
            (new AdminSeeder)->run(); // $this->seed() meminta konfirmasi interaktif di production
            $this->fail('Seeder harus menolak kata sandi bawaan di production');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_PASSWORD', $e->getMessage());
        }

        $this->assertSame(0, Akun::count());
    }

    public function test_production_deploy_ulang_tanpa_admin_password_aman_bila_admin_sudah_ada(): void
    {
        (new AdminSeeder)->run(); // dibuat saat setup awal (di luar production, kata sandi bawaan)
        $this->app['env'] = 'production';
        config(['rental.admin_password' => 'Admin12345']); // ADMIN_PASSWORD sudah dihapus dari .env

        (new AdminSeeder)->run(); // migrate --seed pada deploy berikutnya tidak boleh gagal

        $this->assertSame(1, Akun::where('role', 'admin')->count());
    }

    public function test_production_dengan_admin_password_sendiri_membuat_akun(): void
    {
        $this->app['env'] = 'production';
        config(['rental.admin_password' => 'KataSandiKuat-987']);

        (new AdminSeeder)->run();

        $this->assertSame(1, Akun::where('role', 'admin')->count());
    }

    public function test_admin_password_kosong_di_env_dianggap_tidak_diisi(): void
    {
        // .env.example memuat "ADMIN_PASSWORD=" (kosong) setelah disalin; nilai bawaan config harus tetap berlaku
        $config = require base_path('config/rental.php');
        putenv('ADMIN_PASSWORD=');
        $_ENV['ADMIN_PASSWORD'] = $_SERVER['ADMIN_PASSWORD'] = '';

        try {
            $this->assertSame('Admin12345', (require base_path('config/rental.php'))['admin_password']);
        } finally {
            putenv('ADMIN_PASSWORD');
            unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);
        }
    }
}
