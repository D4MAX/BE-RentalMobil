<?php

namespace Tests\Feature;

use App\Models\Akun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Batas laju tambahan: login per email, lupa kata sandi per email, dan daftar per IP. Tiap permintaan memakai IP berbeda
 * (REMOTE_ADDR) supaya yang diuji hanya batas yang bersangkutan, bukan batas per IP yang sudah ada.
 */
class BatasLajuTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    private function dariIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    // ---- login ----

    public function test_login_dibatasi_10_per_jam_per_email_walau_ganti_ip_dan_huruf_besar_kecil_dianggap_sama(): void
    {
        Akun::create(['email' => 'a@mail.test', 'password' => 'benar12345', 'email_terverifikasi_at' => now()]);

        for ($i = 1; $i <= 10; $i++) {
            $email = $i % 2 ? 'a@mail.test' : 'A@Mail.TEST';
            $this->dariIp("10.0.0.{$i}")->postJson(self::API.'/auth/login', ['email' => $email, 'password' => 'salah'])->assertUnauthorized();
        }

        // Percobaan ke-11 dari IP yang belum pernah dipakai, bahkan dengan kata sandi benar: kena batas email
        $this->dariIp('10.0.0.99')->postJson(self::API.'/auth/login', ['email' => 'a@mail.test', 'password' => 'benar12345'])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');

        // Email lain dari IP baru tidak ikut terkunci
        $this->dariIp('10.0.0.98')->postJson(self::API.'/auth/login', ['email' => 'b@mail.test', 'password' => 'x'])->assertUnauthorized();

        // Sesudah satu jam boleh lagi
        $this->travel(61)->minutes();
        $this->dariIp('10.0.0.97')->postJson(self::API.'/auth/login', ['email' => 'a@mail.test', 'password' => 'benar12345'])->assertOk();
    }

    public function test_login_batas_per_ip_5_per_menit_tetap_ada_untuk_email_yang_berbeda_beda(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->dariIp('10.0.9.1')->postJson(self::API.'/auth/login', ['email' => "u{$i}@mail.test", 'password' => 'x'])->assertUnauthorized();
        }

        $this->dariIp('10.0.9.1')->postJson(self::API.'/auth/login', ['email' => 'u6@mail.test', 'password' => 'x'])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
    }

    public function test_login_tanpa_email_atau_dengan_email_bukan_teks_tetap_422_bukan_500(): void
    {
        $this->dariIp('10.0.8.1')->postJson(self::API.'/auth/login', ['password' => 'x'])->assertStatus(422);
        $this->dariIp('10.0.8.2')->postJson(self::API.'/auth/login', ['email' => ['a@mail.test'], 'password' => 'x'])->assertStatus(422);
    }

    // ---- lupa kata sandi ----

    public function test_lupa_password_dibatasi_3_per_jam_per_email_walau_ganti_ip(): void
    {
        foreach (['budi@mail.test', 'Budi@Mail.test', 'BUDI@mail.test'] as $i => $email) {
            $this->dariIp('10.0.1.'.($i + 1))->postJson(self::API.'/auth/lupa-password', ['email' => $email])->assertOk();
        }

        $this->dariIp('10.0.1.99')->postJson(self::API.'/auth/lupa-password', ['email' => 'budi@mail.test'])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
        $this->dariIp('10.0.1.98')->postJson(self::API.'/auth/lupa-password', ['email' => 'lain@mail.test'])->assertOk();

        $this->travel(61)->minutes();
        $this->dariIp('10.0.1.97')->postJson(self::API.'/auth/lupa-password', ['email' => 'budi@mail.test'])->assertOk();
    }

    public function test_lupa_password_batas_per_ip_5_per_menit_tetap_ada(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->dariIp('10.0.2.1')->postJson(self::API.'/auth/lupa-password', ['email' => "u{$i}@mail.test"])->assertOk();
        }

        $this->dariIp('10.0.2.1')->postJson(self::API.'/auth/lupa-password', ['email' => 'u6@mail.test'])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
    }

    // ---- daftar ----

    public function test_register_dibatasi_10_per_jam_per_ip(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->dariIp('10.0.3.1')->postJson(self::API.'/auth/register', [])->assertStatus(422);
        }

        $this->dariIp('10.0.3.1')->postJson(self::API.'/auth/register', [])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
        $this->dariIp('10.0.3.2')->postJson(self::API.'/auth/register', [])->assertStatus(422);

        $this->travel(61)->minutes();
        $this->dariIp('10.0.3.1')->postJson(self::API.'/auth/register', [])->assertStatus(422);
    }

    // ---- input bukan teks ----

    public function test_email_bukan_teks_pada_kirim_ulang_dan_lupa_password_dijawab_422_atau_429_bukan_500(): void
    {
        foreach (['kirim-ulang-verifikasi' => '10.0.5.1', 'lupa-password' => '10.0.6.1'] as $endpoint => $ip) {
            $url = self::API."/auth/{$endpoint}";

            // email[]=x lewat body JSON dan lewat query string: dulu (string) pada array memicu ErrorException, yaitu 500
            foreach ([['email' => ['x']], ['email' => ['x', 'y']]] as $body) {
                $this->assertContains($this->dariIp($ip)->postJson($url, $body)->getStatusCode(), [422, 429], "{$endpoint} body");
            }
            $this->assertContains($this->dariIp($ip)->postJson($url.'?email[]=x', [])->getStatusCode(), [422, 429], "{$endpoint} query");
        }

        // Tanpa email teks, batasnya jatuh ke IP saja: IP yang sama kena 429 sesudah jatahnya habis (kirim-ulang: 3 per jam),
        // tetapi IP lain tidak ikut terkunci oleh kunci bersama "array"
        $this->dariIp('10.0.5.1')->postJson(self::API.'/auth/kirim-ulang-verifikasi', ['email' => ['x']])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
        $this->dariIp('10.0.5.2')->postJson(self::API.'/auth/kirim-ulang-verifikasi', ['email' => ['x']])->assertStatus(422);
    }

    // ---- verifikasi email: sengaja tidak dibatasi ----

    public function test_verifikasi_email_tidak_dibatasi(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->dariIp('10.0.4.1')->postJson(self::API.'/auth/verifikasi-email', ['token' => 'salah'])
                ->assertStatus(422)->assertJsonPath('code', 'TOKEN_TIDAK_VALID');
        }
    }
}
