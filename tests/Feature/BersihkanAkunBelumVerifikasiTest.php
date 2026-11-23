<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Pelanggan;
use App\Models\TokenAkun;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PRD: akun yang tidak diverifikasi dihapus otomatis setelah 30 hari (supaya emailnya bebas dipakai lagi). */
class BersihkanAkunBelumVerifikasiTest extends TestCase
{
    use RefreshDatabase;

    private function akun(string $email, array $ubah = [], ?int $umurHari = null): Akun
    {
        $akun = Akun::create($ubah + ['email' => $email, 'password' => 'abc12345', 'role' => 'user']);
        Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'X', 'no_hp' => '0812345678', 'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang']);
        if ($umurHari !== null) {
            $akun->forceFill(['created_at' => now()->subDays($umurHari)])->save();
        }

        return $akun;
    }

    public function test_terdaftar_di_scheduler_harian(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'akun:bersihkan-belum-verifikasi'));

        $this->assertNotNull($event, 'Perintah belum didaftarkan di routes/console.php');
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_menghapus_hanya_user_belum_terverifikasi_yang_lebih_dari_30_hari(): void
    {
        $basi = $this->akun('basi@mail.test', [], 31);
        TokenAkun::create(['id_akun' => $basi->id_akun, 'jenis' => 'verifikasi_email', 'token_hash' => str_repeat('a', 64), 'kedaluwarsa_at' => now()->subDays(30)]);
        $baru = $this->akun('baru@mail.test', [], 29);
        $terverifikasi = $this->akun('ok@mail.test', ['email_terverifikasi_at' => now()->subDays(60)], 60);
        $admin = Akun::create(['nama' => 'Adm', 'email' => 'adm@mail.test', 'password' => 'abc12345', 'role' => 'admin']);
        $admin->forceFill(['created_at' => now()->subDays(90)])->save(); // admin tanpa verifikasi email tidak boleh tersentuh

        $this->artisan('akun:bersihkan-belum-verifikasi')->expectsOutput('Akun belum terverifikasi dihapus: 1')->assertSuccessful();

        $this->assertNull(Akun::find($basi->id_akun));
        $this->assertSame(0, Pelanggan::where('id_akun', $basi->id_akun)->count(), 'Pelanggan ikut terhapus');
        $this->assertSame(0, TokenAkun::where('id_akun', $basi->id_akun)->count(), 'Token ikut terhapus');
        foreach ([$baru, $terverifikasi, $admin] as $utuh) {
            $this->assertNotNull(Akun::find($utuh->id_akun), "{$utuh->email} tidak boleh dihapus");
        }
    }

    public function test_emailnya_bisa_dipakai_mendaftar_lagi_setelah_dibersihkan(): void
    {
        $this->akun('basi@mail.test', [], 31);
        $this->artisan('akun:bersihkan-belum-verifikasi')->assertSuccessful();

        $this->assertSame(0, Akun::where('email', 'basi@mail.test')->count());
    }
}
