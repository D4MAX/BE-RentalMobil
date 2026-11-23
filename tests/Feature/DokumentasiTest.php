<?php

namespace Tests\Feature;

use Tests\TestCase;

/** /docs (Swagger UI) dan /openapi.yaml: hidup di luar production, 404 di production, tanpa login dan tanpa cookie. */
class DokumentasiTest extends TestCase
{
    public function test_docs_memuat_swagger_ui_dari_cdn_dengan_versi_dan_hash_terkunci(): void
    {
        $r = $this->get('/docs')->assertOk();

        $r->assertSee('https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css', false)
            ->assertSee('https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js', false)
            ->assertSee('<div id="swagger"></div>', false)
            ->assertSee("url: '/openapi.yaml'", false)
            ->assertSee("dom_id: '#swagger'", false)
            ->assertSee('persistAuthorization: true', false);

        // Hash SRI mengunci isi berkas CDN (dihitung dari berkas versi 5.17.14)
        $this->assertSame(2, substr_count($r->getContent(), 'integrity="sha384-'));
        $this->assertSame(2, substr_count($r->getContent(), 'crossorigin="anonymous"'));
    }

    public function test_openapi_yaml_disajikan_dari_root_proyek(): void
    {
        $r = $this->get('/openapi.yaml')->assertOk();

        $this->assertStringContainsString('application/yaml', $r->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $r->headers->get('Cache-Control'));
        $isi = $r->streamedContent();
        $this->assertStringStartsWith('openapi: 3.0.3', $isi);
        $this->assertSame(file_get_contents(base_path('openapi.yaml')), $isi);
    }

    public function test_tidak_memerlukan_login_dan_tidak_memasang_cookie_sesi(): void
    {
        foreach (['/docs', '/openapi.yaml'] as $url) {
            $r = $this->get($url)->assertOk();
            $this->assertSame([], $r->headers->getCookies(), "{$url} tidak boleh memasang cookie (tanpa sesi dan CSRF)");
        }
    }

    public function test_berkas_spesifikasi_tidak_ada_di_public_agar_aturan_production_berlaku(): void
    {
        $this->assertFileDoesNotExist(public_path('openapi.yaml'), 'Berkas di public/ dilayani langsung web server tanpa lewat Laravel');
        $this->assertFileExists(base_path('openapi.yaml'));
    }

    public function test_di_production_keduanya_404(): void
    {
        $this->app['env'] = 'production';

        $this->get('/docs')->assertNotFound();
        $this->get('/openapi.yaml')->assertNotFound();
    }

    public function test_lingkungan_selain_production_hidup(): void
    {
        foreach (['local', 'staging', 'testing'] as $env) {
            $this->app['env'] = $env;
            $this->get('/docs')->assertOk();
        }
    }

    public function test_api_tetap_menuntut_login_dan_tidak_terpengaruh(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'BELUM_LOGIN');
        $this->getJson('/api/v1/info')->assertOk();
    }

    public function test_setiap_rute_api_terdokumentasi_di_openapi_dan_sebaliknya(): void
    {
        // Path dan metode di openapi.yaml: baris "  /api/v1/...:" lalu "    get|post|put|patch|delete:"
        $dokumen = [];
        $path = null;
        foreach (preg_split('/\R/', file_get_contents(base_path('openapi.yaml'))) as $baris) {
            if (preg_match('#^  (/api/v1\S*):\s*$#', $baris, $m)) {
                $path = preg_replace('/\{[^}]+\}/', '{}', $m[1]);
            } elseif ($path && preg_match('/^    (get|post|put|patch|delete):\s*$/', $baris, $m)) {
                $dokumen[] = strtoupper($m[1]).' '.$path;
            } elseif (preg_match('/^\S/', $baris)) {
                $path = null; // keluar dari blok paths
            }
        }

        $rute = [];
        foreach (app('router')->getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD']) as $metode) {
                $rute[] = $metode.' /'.preg_replace('/\{[^}]+\}/', '{}', $r->uri());
            }
        }

        sort($dokumen);
        sort($rute);
        $this->assertSame([], array_values(array_diff($rute, $dokumen)), 'Rute ada di kode tetapi belum ada di openapi.yaml');
        $this->assertSame([], array_values(array_diff($dokumen, $rute)), 'Ada di openapi.yaml tetapi rutenya tidak ada di kode');
    }
}
