<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Tests\TestCase;

/** TRUSTED_PROXIES (IP di balik proxy) dan CORS_ALLOWED_ORIGINS. Tidak menyentuh database. */
class ProxyDanCorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::setTrustedProxies([], 0); // keadaan statis Symfony: jangan bocor dari atau ke tes lain
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
        parent::tearDown();
    }

    /** Memuat berkas config dengan variabel lingkungan tertentu (null = tidak ada), lalu memulihkannya. */
    private function muatConfig(string $berkas, array $env): array
    {
        $lama = [];
        foreach ($env as $k => $v) {
            $lama[$k] = [getenv($k), $_ENV[$k] ?? null, $_SERVER[$k] ?? null];
            if ($v === null) {
                putenv($k);
                unset($_ENV[$k], $_SERVER[$k]);
            } else {
                putenv("{$k}={$v}");
                $_ENV[$k] = $_SERVER[$k] = $v;
            }
        }

        try {
            return require base_path("config/{$berkas}.php");
        } finally {
            foreach ($lama as $k => [$getenv, $envLama, $serverLama]) {
                $getenv === false ? putenv($k) : putenv("{$k}={$getenv}");
                $envLama === null ? $this->hapusVariabel($_ENV, $k) : $_ENV[$k] = $envLama;
                $serverLama === null ? $this->hapusVariabel($_SERVER, $k) : $_SERVER[$k] = $serverLama;
            }
        }
    }

    private function hapusVariabel(array &$sumber, string $kunci): void
    {
        unset($sumber[$kunci]);
    }

    private function ipTerbaca(string $remote, ?string $diteruskan = null): string
    {
        $this->app['router']->get('/_ip_uji', fn (Request $r) => response($r->ip()));
        $server = ['REMOTE_ADDR' => $remote] + ($diteruskan ? ['HTTP_X_FORWARDED_FOR' => $diteruskan] : []);

        return $this->call('GET', '/_ip_uji', [], [], [], $server)->getContent();
    }

    // ---- TRUSTED_PROXIES ----

    public function test_tanpa_proxy_tepercaya_header_forwarded_diabaikan(): void
    {
        config(['trustedproxy.proxies' => null]);

        $this->assertSame('10.0.0.1', $this->ipTerbaca('10.0.0.1', '203.0.113.5'));
    }

    public function test_proxy_tepercaya_membuat_ip_pengguna_asli_terbaca_dan_proxy_lain_tidak_dipercaya(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1']);

        $this->assertSame('203.0.113.5', $this->ipTerbaca('10.0.0.1', '203.0.113.5'));
        $this->assertSame('10.0.0.2', $this->ipTerbaca('10.0.0.2', '203.0.113.5'), 'Penyambung yang bukan proxy tepercaya tidak boleh memalsukan IP');
    }

    public function test_config_trusted_proxies_dibaca_dari_env_dan_kosong_berarti_tidak_percaya(): void
    {
        $this->assertNull($this->muatConfig('trustedproxy', ['TRUSTED_PROXIES' => ''])['proxies']);
        $this->assertNull($this->muatConfig('trustedproxy', ['TRUSTED_PROXIES' => null])['proxies']);
        $this->assertSame('10.0.0.1,10.0.0.2', $this->muatConfig('trustedproxy', ['TRUSTED_PROXIES' => '10.0.0.1,10.0.0.2'])['proxies']);
    }

    // ---- CORS: isi config ----

    public function test_cors_origin_dari_env_dipisah_koma_dirapikan_dan_default_mengikuti_frontend_url(): void
    {
        $this->assertSame(['https://a.test', 'https://b.test'],
            $this->muatConfig('cors', ['CORS_ALLOWED_ORIGINS' => 'https://a.test, https://b.test/', 'FRONTEND_URL' => 'https://front.test'])['allowed_origins']);
        $this->assertSame(['https://front.test'],
            $this->muatConfig('cors', ['CORS_ALLOWED_ORIGINS' => '', 'FRONTEND_URL' => 'https://front.test/'])['allowed_origins']);
        $this->assertSame(['http://localhost:5173'],
            $this->muatConfig('cors', ['CORS_ALLOWED_ORIGINS' => null, 'FRONTEND_URL' => null])['allowed_origins']);
    }

    public function test_cors_tidak_memakai_wildcard_dan_tanpa_credentials(): void
    {
        $cors = $this->muatConfig('cors', ['CORS_ALLOWED_ORIGINS' => 'https://a.test']);

        $this->assertSame(['api/*'], $cors['paths']);
        $this->assertFalse($cors['supports_credentials']);
        foreach (['allowed_origins', 'allowed_methods', 'allowed_headers'] as $kunci) {
            $this->assertNotContains('*', $cors[$kunci], $kunci);
        }
    }

    // ---- CORS: perilaku ----

    public function test_preflight_dari_origin_yang_diizinkan_lolos_tanpa_credentials(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, content-type',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_origin_lain_tidak_mendapat_izin_cors_untuk_preflight_maupun_permintaan_biasa(): void
    {
        // Dua origin atau lebih: pustaka CORS hanya menggaungkan origin yang terdaftar, jadi origin asing tidak mendapat header
        config(['cors.allowed_origins' => ['http://localhost:5173', 'https://admin.example']]);

        $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://jahat.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://jahat.example', 'HTTP_ACCEPT' => 'application/json'])
            ->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://admin.example', 'HTTP_ACCEPT' => 'application/json'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin.example');
    }

    public function test_dengan_satu_origin_terdaftar_origin_asing_tidak_pernah_digaungkan(): void
    {
        // Satu origin: pustaka selalu memasang origin terdaftar itu, dan browser menolak halaman asing karena tidak cocok
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $r = $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://jahat.example', 'HTTP_ACCEPT' => 'application/json']);

        $this->assertSame('http://localhost:5173', $r->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('https://jahat.example', $r->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_permintaan_dari_origin_yang_diizinkan_mendapat_header_dan_header_penting_terbaca_frontend(): void
    {
        config(['cors.allowed_origins' => ['http://localhost:5173']]);

        $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:5173', 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422)
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Expose-Headers', 'Retry-After, Content-Disposition');
    }

    public function test_swagger_satu_origin_dengan_api_tetap_jalan_walau_origin_tidak_didaftarkan(): void
    {
        config(['cors.allowed_origins' => ['https://frontend.example']]);

        // Swagger di /docs memanggil API dari origin yang sama (http://localhost pada tes ini): CORS tidak ikut campur
        $this->get('/docs')->assertOk();
        $this->call('POST', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json'])
            ->assertStatus(422);
    }

    // ---- dokumentasi variabel ----

    public function test_env_example_mendokumentasikan_variabel_baru(): void
    {
        $contoh = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^TRUSTED_PROXIES=/m', $contoh);
        $this->assertMatchesRegularExpression('/^CORS_ALLOWED_ORIGINS=/m', $contoh);
        $this->assertStringContainsString('Kosong = tidak percaya proxy mana pun', $contoh);
        $this->assertStringContainsString('Kosong = FRONTEND_URL', $contoh);
    }
}
