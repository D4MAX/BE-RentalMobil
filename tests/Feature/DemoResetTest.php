<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * php artisan demo:reset. Tidak pernah menyentuh database dev: nama database yang diizinkan dialihkan ke database test
 * (rental_mobil_test), dan disk penyimpanan diganti disk palsu. Tanpa RefreshDatabase karena perintahnya sendiri menjalankan
 * migrate:fresh; setelahnya skema test dibuat ulang tanpa data.
 */
class DemoResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pengaman test: bila ini bukan database test, hentikan sebelum apa pun dijalankan
        $this->assertSame('rental_mobil_test', DB::connection()->getDatabaseName());
        Storage::fake('public');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            Artisan::call('migrate:fresh', ['--force' => true]); // kosongkan data seed agar tes lain tidak terpengaruh
        }
        parent::tearDown();
    }

    private function izinkanDatabaseTest(): void
    {
        config(['rental.demo_reset_database' => 'rental_mobil_test']);
    }

    private function buatBerkasUpload(): void
    {
        foreach (['tipe/1/a.jpg', 'profil/9/b.png'] as $p) {
            Storage::disk('public')->put($p, 'x');
        }
        foreach (['jaminan/3/k.jpg', 'serah-terima/3/s.jpg', 'pengembalian/3/p.jpg'] as $p) {
            Storage::disk('local')->put($p, 'x');
        }
        // Folder di luar daftar upload aplikasi: tidak boleh disentuh
        Storage::disk('public')->put('lain/jangan-disentuh.txt', 'x');
        Storage::disk('local')->put('lain/jangan-disentuh.txt', 'x');
    }

    public function test_menolak_di_production_tanpa_menyentuh_apa_pun(): void
    {
        $this->izinkanDatabaseTest();
        $this->app['env'] = 'production';
        $this->buatBerkasUpload();
        $akun = Akun::create(['email' => 'a@b.test', 'password' => 'x12345678', 'role' => 'user']);

        $this->artisan('demo:reset', ['--force' => true])->expectsOutputToContain('production')->assertFailed();

        $this->assertNotNull(Akun::find($akun->id_akun), 'Data tidak dihapus');
        Storage::disk('public')->assertExists('tipe/1/a.jpg');
    }

    public function test_menolak_database_selain_rental_mobil(): void
    {
        $this->buatBerkasUpload();
        $akun = Akun::create(['email' => 'a@b.test', 'password' => 'x12345678', 'role' => 'user']);

        // Nilai env bisa berbeda; yang diizinkan selain database aktif (rental_mobil_test) harus ditolak
        config(['rental.demo_reset_database' => 'rental_mobil']);
        $this->artisan('demo:reset', ['--force' => true])->expectsOutputToContain('rental_mobil_test')->assertFailed();

        $this->assertNotNull(Akun::find($akun->id_akun));
        Storage::disk('public')->assertExists('tipe/1/a.jpg');
    }

    public function test_nama_database_proyek_lain_tidak_pernah_dianggap_rental_mobil(): void
    {
        $this->assertSame(['rentalmobil', 'rentalmobil_test'], config('rental.demo_reset_terlarang'));
    }

    public function test_database_proyek_lain_ditolak_walau_diizinkan_lewat_env(): void
    {
        $this->buatBerkasUpload();
        $akun = Akun::create(['email' => 'a@b.test', 'password' => 'x12345678', 'role' => 'user']);

        // Database aktif tes dianggap "rentalmobil_test" dan env disetel sama: tetap harus ditolak
        config(['rental.demo_reset_database' => 'rental_mobil_test', 'rental.demo_reset_terlarang' => ['rental_mobil_test']]);
        $this->artisan('demo:reset', ['--force' => true])->expectsOutputToContain('milik proyek lain')->assertFailed();

        $this->assertNotNull(Akun::find($akun->id_akun));
        Storage::disk('public')->assertExists('tipe/1/a.jpg');
    }

    public function test_tanpa_konfirmasi_dibatalkan_dan_tidak_ada_yang_berubah(): void
    {
        $this->izinkanDatabaseTest();
        $this->buatBerkasUpload();
        $akun = Akun::create(['email' => 'a@b.test', 'password' => 'x12345678', 'role' => 'user']);

        $this->artisan('demo:reset')
            ->expectsOutputToContain('rental_mobil_test')
            ->expectsConfirmation('Semua data di database "rental_mobil_test" akan HILANG. Lanjutkan?', 'no')
            ->expectsOutputToContain('Dibatalkan')
            ->assertFailed();

        $this->assertNotNull(Akun::find($akun->id_akun));
        Storage::disk('public')->assertExists('tipe/1/a.jpg');
    }

    public function test_dengan_konfirmasi_reset_seed_dan_hapus_berkas_yatim(): void
    {
        $this->izinkanDatabaseTest();
        $this->buatBerkasUpload();
        $akun = Akun::create(['email' => 'lama@b.test', 'password' => 'x12345678', 'role' => 'user']);

        $this->artisan('demo:reset')
            ->expectsConfirmation('Semua data di database "rental_mobil_test" akan HILANG. Lanjutkan?', 'yes')
            ->expectsOutputToContain('Setelah reset')
            ->assertSuccessful();

        $this->verifikasiHasilReset($akun->id_akun);
    }

    public function test_opsi_force_melewati_konfirmasi(): void
    {
        $this->izinkanDatabaseTest();
        $this->buatBerkasUpload();
        $akun = Akun::create(['email' => 'lama@b.test', 'password' => 'x12345678', 'role' => 'user']);

        $this->artisan('demo:reset', ['--force' => true])->expectsOutputToContain('Berkas yatim dihapus : 5')->assertSuccessful();

        $this->verifikasiHasilReset($akun->id_akun);
    }

    public function test_tempat_sampah_tipe_dan_unit_ikut_terhapus_beserta_fotonya(): void
    {
        $this->izinkanDatabaseTest();
        $tipe = TipeKendaraan::create(['nama' => 'Lama', 'merk' => 'Merk', 'kursi' => 5, 'bahan_bakar' => 'bensin', 'harga_sopir_per_hari' => 1]);
        $unit = Kendaraan::create(['id_tipe' => $tipe->id_tipe, 'plat_nomor' => 'N 9 ZZ', 'tahun' => 2022, 'warna' => 'Putih', 'transmisi' => 'manual']);
        $this->buatBerkasUpload(); // tipe/1/a.jpg: foto tipe yang sudah di tempat sampah
        $unit->delete();
        $tipe->delete();
        $this->assertSame(1, TipeKendaraan::onlyTrashed()->count());

        $this->artisan('demo:reset', ['--force' => true])->expectsOutputToContain('Sampah    : 0 tipe, 0 unit')->assertSuccessful();

        $this->assertSame(0, TipeKendaraan::onlyTrashed()->count());
        $this->assertSame(0, Kendaraan::onlyTrashed()->count());
        $this->assertSame(['Avanza', 'Brio', 'Xenia'], TipeKendaraan::withTrashed()->orderBy('nama')->pluck('nama')->all());
        $this->assertSame([], Storage::disk('public')->allFiles('tipe'));
    }

    private function verifikasiHasilReset(int $idAkunLama): void
    {
        $this->assertNull(Akun::where('email', 'lama@b.test')->first(), 'Data lama hilang');
        $this->assertSame([config('rental.admin_email')], Akun::pluck('email')->all(), 'Hanya akun seed (admin)');
        $this->assertSame(3, TipeKendaraan::count());
        $this->assertSame(5, Kendaraan::count());

        foreach (['tipe', 'profil'] as $f) {
            $this->assertSame([], Storage::disk('public')->allFiles($f), "public/{$f} bersih");
        }
        foreach (['jaminan', 'serah-terima', 'pengembalian'] as $f) {
            $this->assertSame([], Storage::disk('local')->allFiles($f), "local/{$f} bersih");
        }
        Storage::disk('public')->assertExists('lain/jangan-disentuh.txt');
        Storage::disk('local')->assertExists('lain/jangan-disentuh.txt');
    }
}
