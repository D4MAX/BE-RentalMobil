<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Pelanggan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Masa berlaku token login: admin 30 menit dan user 60 menit (config rental.token_admin_menit dan token_user_menit),
 * diperpanjang selama ada aktivitas (idle timeout, middleware PerpanjangToken), tanpa refresh token.
 */
class MasaBerlakuTokenTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    private function buatAkun(string $role, array $ubah = []): Akun
    {
        $akun = Akun::create($ubah + ['nama' => 'Tes', 'email' => "{$role}@mail.test", 'password' => 'benar12345', 'role' => $role, 'email_terverifikasi_at' => now()]);
        if ($role === 'user') {
            Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'Budi', 'no_hp' => '081234567890', 'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang']);
        }

        return $akun;
    }

    /** @return array{0: Akun, 1: string} akun dan token hasil login sungguhan */
    private function masuk(string $role): array
    {
        $akun = $this->buatAkun($role);
        $token = $this->postJson(self::API.'/auth/login', ['email' => $akun->email, 'password' => 'benar12345'])->assertOk()->json('data.token');

        return [$akun, $token];
    }

    private function panggil(string $metode, string $url, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metode, self::API.$url);
    }

    private function kedaluwarsa(Akun $akun)
    {
        return $akun->tokens()->first()->fresh()->expires_at;
    }

    private function atur(Akun $akun, $waktu): void
    {
        $akun->tokens()->update(['expires_at' => $waktu]);
    }

    public function test_masa_berlaku_token_per_peran_dari_config(): void
    {
        $this->assertSame(30, config('rental.token_admin_menit'));
        $this->assertSame(60, config('rental.token_user_menit'));

        [$admin] = $this->masuk('admin');
        [$user] = $this->masuk('user');

        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($this->kedaluwarsa($admin)), 5);
        $this->assertEqualsWithDelta(60 * 60, now()->diffInSeconds($this->kedaluwarsa($user)), 5);
        $this->assertNull(config('sanctum.expiration'), 'sanctum.expiration harus null agar tidak menimpa expires_at per token');
    }

    public function test_masa_berlaku_bisa_diubah_lewat_config(): void
    {
        config(['rental.token_admin_menit' => 5, 'rental.token_user_menit' => 120]);

        [$admin] = $this->masuk('admin');
        [$user] = $this->masuk('user');

        $this->assertEqualsWithDelta(5 * 60, now()->diffInSeconds($this->kedaluwarsa($admin)), 5);
        $this->assertEqualsWithDelta(120 * 60, now()->diffInSeconds($this->kedaluwarsa($user)), 5);
    }

    public function test_token_kedaluwarsa_ditolak_401_dengan_format_error_yang_sama_dan_tidak_diperpanjang(): void
    {
        foreach (['admin', 'user'] as $role) {
            [$akun, $token] = $this->masuk($role);
            $this->atur($akun, now()->subMinute());
            $sebelum = $this->kedaluwarsa($akun);

            $this->panggil('GET', '/profil', $token)->assertUnauthorized()
                ->assertExactJson(['message' => 'Belum login', 'code' => 'BELUM_LOGIN']);

            $this->assertTrue($this->kedaluwarsa($akun)->equalTo($sebelum), "{$role}: token kedaluwarsa tidak boleh diperpanjang");
        }
    }

    public function test_token_diperpanjang_saat_aktif_dan_sisa_tinggal_separuh(): void
    {
        foreach (['admin' => 30, 'user' => 60] as $role => $menit) {
            [$akun, $token] = $this->masuk($role);
            $this->atur($akun, now()->addMinutes((int) ($menit / 2) - 1)); // sisa kurang dari separuh

            $this->panggil('GET', '/profil', $token)->assertOk();

            $this->assertEqualsWithDelta($menit * 60, now()->diffInSeconds($this->kedaluwarsa($akun)), 5, "{$role}: diperpanjang penuh");
            $this->panggil('GET', '/profil', $token)->assertOk(); // token tetap sah
        }
    }

    public function test_tidak_ada_query_perpanjangan_selama_sisa_waktu_masih_lebih_dari_separuh(): void
    {
        [$akun, $token] = $this->masuk('user');
        $this->atur($akun, now()->addMinutes(50)); // 50 dari 60 menit
        $sebelum = $this->kedaluwarsa($akun);

        DB::enableQueryLog();
        $this->panggil('GET', '/profil', $token)->assertOk();
        $perpanjangan = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'update `personal_access_tokens`') && str_contains($q['query'], '`expires_at`'));
        DB::disableQueryLog();

        $this->assertCount(0, $perpanjangan);
        $this->assertTrue($this->kedaluwarsa($akun)->equalTo($sebelum));
    }

    public function test_perpanjangan_hanya_satu_update_dan_permintaan_berikutnya_tidak_menambah(): void
    {
        [$akun, $token] = $this->masuk('admin');
        $this->atur($akun, now()->addMinutes(10)); // di bawah separuh dari 30

        DB::enableQueryLog();
        $this->panggil('GET', '/profil', $token)->assertOk();
        $this->panggil('GET', '/profil', $token)->assertOk();
        $this->panggil('GET', '/profil', $token)->assertOk();
        $perpanjangan = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'update `personal_access_tokens`') && str_contains($q['query'], '`expires_at`'));
        DB::disableQueryLog();

        $this->assertCount(1, $perpanjangan, 'hanya permintaan pertama yang memperpanjang');
    }

    public function test_permintaan_gagal_tidak_memperpanjang(): void
    {
        [$user, $tokenUser] = $this->masuk('user');
        $this->atur($user, now()->addMinutes(5));
        $sebelum = $this->kedaluwarsa($user);

        $this->panggil('GET', '/admin/dashboard', $tokenUser)->assertForbidden();   // 403 peran
        $this->panggil('GET', '/pemesanan/99999', $tokenUser)->assertNotFound();    // 404
        $this->panggil('PUT', '/profil/password', $tokenUser)->assertStatus(422);   // 422 validasi

        $this->assertTrue($this->kedaluwarsa($user)->equalTo($sebelum), 'permintaan gagal tidak memperpanjang');

        // Admin dengan kata sandi sementara: 403 GANTI_PASSWORD_DULU juga tidak memperpanjang
        $admin = $this->buatAkun('admin', ['email' => 'sementara@mail.test', 'wajib_ganti_password' => true]);
        $token = $this->postJson(self::API.'/auth/login', ['email' => $admin->email, 'password' => 'benar12345'])->json('data.token');
        $this->atur($admin, now()->addMinutes(5));
        $sebelum = $this->kedaluwarsa($admin);
        $this->panggil('GET', '/profil', $token)->assertForbidden()->assertJsonPath('code', 'GANTI_PASSWORD_DULU');
        $this->assertTrue($this->kedaluwarsa($admin)->equalTo($sebelum));

        // Setelah berhasil (ganti kata sandi), token diperpanjang
        $this->panggil('PUT', '/profil/password', $token)->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson(self::API.'/profil/password', ['password_lama' => 'benar12345', 'password_baru' => 'baru12345', 'konfirmasi' => 'baru12345'])->assertOk();
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($this->kedaluwarsa($admin)), 5);
    }

    public function test_logout_tidak_menghidupkan_token_dan_token_tanpa_masa_berlaku_tidak_disentuh(): void
    {
        [$akun, $token] = $this->masuk('user');
        $this->atur($akun, now()->addMinutes(5));
        $this->panggil('POST', '/auth/logout', $token)->assertNoContent();
        $this->assertSame(0, $akun->tokens()->count());
        $this->panggil('GET', '/profil', $token)->assertUnauthorized();

        // Token lama tanpa expires_at dibiarkan apa adanya
        $abadi = $akun->createToken('user', ['user'])->plainTextToken;
        $this->panggil('GET', '/profil', $abadi)->assertOk();
        $this->assertNull($akun->tokens()->first()->expires_at);
    }
}
