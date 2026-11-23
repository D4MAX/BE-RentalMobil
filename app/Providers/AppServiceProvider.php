<?php

namespace App\Providers;

use App\Services\Pembayaran\MidtransGateway;
use App\Services\Pembayaran\PembayaranGateway;
use App\Services\Pembayaran\PembayaranGatewayPalsu;
use App\Support\PemeriksaanGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PEMBAYARAN_GATEWAY=midtrans memakai Midtrans asli; selain itu (bawaan) dan saat testing: gateway palsu.
        // Singleton agar tes bisa mengambil instance yang sama dan memeriksa catatannya.
        $this->app->singleton(PembayaranGateway::class, fn ($app) => ! $app->environment('testing') && config('midtrans.gateway') === 'midtrans'
            ? new MidtransGateway
            : new PembayaranGatewayPalsu);
    }

    public function boot(): void
    {
        // Hanya HTTP, schedule:run, dan queue:work; perintah artisan lain (package:discover, migrate, ...) dilewati
        PemeriksaanGateway::periksaUntukKonteks(
            $this->app->environment(), config('midtrans.gateway'), $this->app->runningInConsole(), $_SERVER['argv'] ?? [],
            (bool) config('app.debug'), config('mail.default')
        );

        // Email dari body untuk kunci batas: huruf kecil, tanpa spasi tepi; bukan teks (mis. email[]=x) atau kosong = tidak ada batas email
        $email = fn (Request $r): string => is_string($r->input('email')) ? mb_strtolower(trim($r->input('email'))) : '';

        // Login: 5 percobaan per menit per IP, dan 10 per jam per email (menahan penebakan satu akun dari banyak IP)
        RateLimiter::for('login', fn (Request $r) => array_values(array_filter([
            Limit::perMinute(5)->by($r->ip()),
            $email($r) !== '' ? Limit::perHour(10)->by('email:'.$email($r)) : null,
        ])));
        // Lupa kata sandi: 5 per menit per IP, dan 3 per jam per email (menahan banjir email ke satu korban dari banyak IP)
        RateLimiter::for('lupa-password', fn (Request $r) => array_values(array_filter([
            Limit::perMinute(5)->by($r->ip()),
            $email($r) !== '' ? Limit::perHour(3)->by('email:'.$email($r)) : null,
        ])));
        // Daftar: 10 per jam per IP
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        // Reset kata sandi: hitungan sendiri agar salah ketik token tidak menghabiskan jatah lupa-password
        RateLimiter::for('reset-password', fn (Request $r) => Limit::perMinute(5)->by($r->ip()));
        // Kirim ulang verifikasi: 3 per jam per email dan IP (email bukan teks: per IP saja)
        RateLimiter::for('kirim-ulang', fn (Request $r) => Limit::perHour(3)->by($email($r).'|'.$r->ip()));
    }
}
