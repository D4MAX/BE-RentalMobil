<?php

use App\Http\Middleware\HanyaNonProduksi;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['nama' => config('app.name'), 'api' => '/api/v1']));

// Dokumentasi API (Swagger UI). Hanya di luar production (di sana 404). Tanpa middleware auth, dan tanpa grup web
// (sesi dan CSRF) karena halaman ini statis: tidak butuh tabel sessions dan tidak memasang cookie. Satu origin dengan
// API, jadi CORS tidak terlibat. Spesifikasi dibaca dari openapi.yaml di root proyek, bukan dari public/, supaya
// aturan production di atas berlaku (berkas di public/ dilayani langsung oleh web server tanpa lewat Laravel).
Route::middleware(HanyaNonProduksi::class)->withoutMiddleware('web')->group(function () {
    Route::view('/docs', 'docs');

    Route::get('/openapi.yaml', fn () => is_file($berkas = base_path('openapi.yaml'))
        ? response()->file($berkas, ['Content-Type' => 'application/yaml; charset=utf-8', 'Cache-Control' => 'no-cache'])
        : abort(404));
});
