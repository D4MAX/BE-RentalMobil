<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Support\PemeriksaanGateway;
use RuntimeException;
use Tests\TestCase;

/** Pengaman boot produksi: tidak boleh memakai gateway palsu, APP_DEBUG=true, atau MAIL_MAILER=log. */
class PemeriksaanGatewayTest extends TestCase
{
    public function test_produksi_dengan_gateway_palsu_atau_kosong_ditolak(): void
    {
        foreach (['palsu', null, ''] as $gateway) {
            try {
                PemeriksaanGateway::pastikanAman('production', $gateway);
                $this->fail('Seharusnya menolak untuk gateway: '.var_export($gateway, true));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('PEMBAYARAN_GATEWAY', $e->getMessage());
            }
        }
    }

    public function test_lingkungan_lain_atau_produksi_dengan_midtrans_lolos(): void
    {
        PemeriksaanGateway::pastikanAman('production', 'midtrans');
        PemeriksaanGateway::pastikanAman('local', 'palsu');
        PemeriksaanGateway::pastikanAman('staging', 'palsu');
        PemeriksaanGateway::pastikanAman('testing', 'palsu');
        $this->addToAssertionCount(1);
    }

    public function test_service_provider_memakai_pemeriksaan_saat_boot(): void
    {
        $this->app['env'] = 'production';
        config(['midtrans.gateway' => 'palsu']);
        $asli = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'queue:work'];

        try {
            $this->expectException(RuntimeException::class);
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $_SERVER['argv'] = $asli;
        }
    }

    public function test_service_provider_lolos_di_produksi_dengan_midtrans(): void
    {
        $this->app['env'] = 'production';
        config(['midtrans.gateway' => 'midtrans', 'app.debug' => false, 'mail.default' => 'smtp']);
        $asli = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'schedule:run'];

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->addToAssertionCount(1);
        } finally {
            $_SERVER['argv'] = $asli;
        }
    }

    public function test_hanya_http_schedule_run_dan_queue_work_yang_diperiksa(): void
    {
        $this->assertTrue(PemeriksaanGateway::perluDiperiksa(false, []), 'permintaan HTTP');
        $this->assertTrue(PemeriksaanGateway::perluDiperiksa(true, ['artisan', 'schedule:run']));
        $this->assertTrue(PemeriksaanGateway::perluDiperiksa(true, ['artisan', 'queue:work', '--tries=3']));

        foreach (['package:discover', 'migrate', 'config:cache', 'route:cache', 'optimize', 'key:generate', 'schedule:list', 'tinker', 'pemesanan:batalkan-kedaluwarsa'] as $perintah) {
            $this->assertFalse(PemeriksaanGateway::perluDiperiksa(true, ['artisan', $perintah]), $perintah);
        }
        $this->assertFalse(PemeriksaanGateway::perluDiperiksa(true, ['artisan']), 'tanpa perintah');
    }

    public function test_produksi_gateway_palsu_ditolak_untuk_http_dan_schedule_run_tetapi_bukan_package_discover(): void
    {
        // HTTP dan schedule:run: ditolak
        foreach ([[false, []], [true, ['artisan', 'schedule:run']], [true, ['artisan', 'queue:work']]] as [$console, $argv]) {
            try {
                PemeriksaanGateway::periksaUntukKonteks('production', 'palsu', $console, $argv);
                $this->fail('Seharusnya menolak: '.json_encode($argv));
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        // Perintah artisan lain tidak boleh terhalang
        PemeriksaanGateway::periksaUntukKonteks('production', 'palsu', true, ['artisan', 'package:discover']);
        PemeriksaanGateway::periksaUntukKonteks('production', null, true, ['artisan', 'migrate', '--force']);
        $this->addToAssertionCount(1);
    }

    public function test_service_provider_membaca_perintah_dari_argv_proses(): void
    {
        $this->app['env'] = 'production';
        config(['midtrans.gateway' => 'palsu']);
        $asli = $_SERVER['argv'] ?? [];

        try {
            // package:discover: lolos walau gateway palsu di produksi
            $_SERVER['argv'] = ['artisan', 'package:discover', '--ansi'];
            (new AppServiceProvider($this->app))->boot();

            // schedule:run: ditolak
            $_SERVER['argv'] = ['artisan', 'schedule:run'];
            $this->expectException(RuntimeException::class);
            (new AppServiceProvider($this->app))->boot();
        } finally {
            $_SERVER['argv'] = $asli;
        }
    }

    public function test_produksi_dengan_debug_menyala_atau_mailer_log_ditolak_dengan_pesan_jelas(): void
    {
        try {
            PemeriksaanGateway::pastikanAman('production', 'midtrans', true, 'smtp');
            $this->fail('APP_DEBUG=true di produksi harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('APP_DEBUG=true', $e->getMessage());
            $this->assertStringContainsString('APP_DEBUG=false', $e->getMessage(), 'pesan memberi tahu cara memperbaiki');
            $this->assertStringNotContainsString('PEMBAYARAN_GATEWAY', $e->getMessage(), 'hanya yang melanggar yang disebut');
        }

        try {
            PemeriksaanGateway::pastikanAman('production', 'midtrans', false, 'log');
            $this->fail('MAIL_MAILER=log di produksi harus ditolak');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('MAIL_MAILER=log', $e->getMessage());
            $this->assertStringContainsString('MAIL_MAILER=smtp', $e->getMessage());
            $this->assertStringNotContainsString('APP_DEBUG', $e->getMessage());
        }
    }

    public function test_semua_pelanggaran_produksi_dilaporkan_sekaligus(): void
    {
        try {
            PemeriksaanGateway::pastikanAman('production', 'palsu', true, 'log');
            $this->fail('Seharusnya menolak');
        } catch (RuntimeException $e) {
            foreach (['PEMBAYARAN_GATEWAY', 'APP_DEBUG=true', 'MAIL_MAILER=log'] as $kata) {
                $this->assertStringContainsString($kata, $e->getMessage());
            }
        }
    }

    public function test_produksi_aman_dan_lingkungan_lain_bebas_debug_dan_mailer_log(): void
    {
        PemeriksaanGateway::pastikanAman('production', 'midtrans', false, 'smtp');
        PemeriksaanGateway::pastikanAman('production', 'midtrans', false, null);
        foreach (['local', 'staging', 'testing'] as $lingkungan) {
            PemeriksaanGateway::pastikanAman($lingkungan, 'palsu', true, 'log');
        }
        // Perintah artisan yang tidak diperiksa tidak terhalang walau pelanggaran ada
        PemeriksaanGateway::periksaUntukKonteks('production', 'midtrans', true, ['artisan', 'migrate', '--force'], true, 'log');
        $this->addToAssertionCount(1);
    }

    public function test_service_provider_menolak_boot_produksi_dengan_debug_menyala_atau_mailer_log(): void
    {
        $this->app['env'] = 'production';
        config(['midtrans.gateway' => 'midtrans']);
        $asli = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['artisan', 'schedule:run'];

        try {
            config(['app.debug' => true, 'mail.default' => 'smtp']);
            try {
                (new AppServiceProvider($this->app))->boot();
                $this->fail('APP_DEBUG=true harus menghentikan boot');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('APP_DEBUG=true', $e->getMessage());
            }

            config(['app.debug' => false, 'mail.default' => 'log']);
            try {
                (new AppServiceProvider($this->app))->boot();
                $this->fail('MAIL_MAILER=log harus menghentikan boot');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('MAIL_MAILER=log', $e->getMessage());
            }

            config(['mail.default' => 'smtp']);
            (new AppServiceProvider($this->app))->boot();
            $this->addToAssertionCount(1);
        } finally {
            $_SERVER['argv'] = $asli;
        }
    }
}
