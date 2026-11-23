<?php

use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\LaporanController;
use App\Http\Controllers\Api\Admin\PemesananAdminController;
use App\Http\Controllers\Api\Admin\ReviewAdminController;
use App\Http\Controllers\Api\Admin\SampahAdminController;
use App\Http\Controllers\Api\Admin\ServisAdminController;
use App\Http\Controllers\Api\Admin\TipeAdminController;
use App\Http\Controllers\Api\Admin\UnitAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KatalogController;
use App\Http\Controllers\Api\PemesananController;
use App\Http\Controllers\Api\ProfilController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Guest
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
        Route::post('/verifikasi-email', [AuthController::class, 'verifikasiEmail']);
        Route::post('/kirim-ulang-verifikasi', [AuthController::class, 'kirimUlangVerifikasi'])->middleware('throttle:kirim-ulang');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::post('/lupa-password', [AuthController::class, 'lupaPassword'])->middleware('throttle:lupa-password');
        Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');
    });

    Route::get('/katalog/tipe', [KatalogController::class, 'index']);
    Route::get('/katalog/tipe/{id}', [KatalogController::class, 'show'])->whereNumber('id');
    Route::get('/info', [KatalogController::class, 'info']);

    // Midtrans (publik; keaslian dijamin tanda tangan, bukan token)
    Route::post('/webhook/midtrans', [WebhookController::class, 'midtrans']);

    // Harus login; admin dengan kata sandi sementara hanya boleh logout dan ganti kata sandi
    Route::middleware(['auth:sanctum', 'perpanjang_token'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        // Boleh dipanggil admin dengan kata sandi sementara
        Route::put('/profil/password', [ProfilController::class, 'password']);

        Route::middleware('wajib_ganti_password')->group(function () {
            Route::get('/me', [AuthController::class, 'me']);
            Route::get('/profil', [ProfilController::class, 'show']);
            Route::post('/profil', [ProfilController::class, 'update']); // multipart saja, foto opsional

            Route::middleware('peran:user')->group(function () {
                Route::post('/pemesanan', [PemesananController::class, 'store']);
                Route::get('/pemesanan', [PemesananController::class, 'index']);
                Route::get('/pemesanan/{id}', [PemesananController::class, 'show'])->whereNumber('id');
                Route::post('/pemesanan/{id}/pembayaran', [PemesananController::class, 'bayar'])->whereNumber('id');
                Route::post('/pemesanan/{id}/batalkan', [PemesananController::class, 'batalkan'])->whereNumber('id');
                Route::post('/pemesanan/{id}/ajukan-pembatalan', [PemesananController::class, 'ajukanPembatalan'])->whereNumber('id');
                Route::get('/pemesanan/{id}/nota', [PemesananController::class, 'nota'])->whereNumber('id');
                Route::post('/pemesanan/{id}/review', [PemesananController::class, 'review'])->whereNumber('id');
            });

            // Admin. Tidak ada endpoint pembatalan oleh admin: hanya setujui atau tolak pengajuan pelanggan.
            Route::middleware('peran:admin')->prefix('admin')->group(function () {
                Route::get('/dashboard', DashboardController::class);

                Route::patch('/review/{id}', [ReviewAdminController::class, 'update'])->whereNumber('id');

                Route::get('/laporan', [LaporanController::class, 'index']);
                Route::get('/laporan/pdf', [LaporanController::class, 'pdf']);

                Route::controller(TipeAdminController::class)->prefix('tipe')->whereNumber('id')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::get('/{id}', 'show');
                    Route::post('/{id}', 'update'); // multipart saja; foto opsional (ditambahkan), tanpa _method
                    Route::delete('/{id}', 'destroy');
                    Route::delete('/{id}/foto/{id_foto}', 'hapusFoto')->whereNumber('id_foto'); // foto satuan dihapus permanen
                });

                // Tempat sampah (soft delete tipe dan unit)
                Route::controller(SampahAdminController::class)->group(function () {
                    Route::get('/sampah', 'index');
                    Route::post('/tipe/{id}/pulihkan', 'pulihkanTipe')->whereNumber('id');
                    Route::post('/unit/{id}/pulihkan', 'pulihkanUnit')->whereNumber('id');
                    Route::delete('/sampah/tipe/{id}', 'hapusPermanenTipe')->whereNumber('id');
                    Route::delete('/sampah/unit/{id}', 'hapusPermanenUnit')->whereNumber('id');
                });

                Route::controller(UnitAdminController::class)->prefix('unit')->whereNumber('id')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::get('/{id}', 'show');
                    Route::put('/{id}', 'update');
                    Route::delete('/{id}', 'destroy');
                    Route::patch('/{id}/status', 'status');
                });

                Route::controller(ServisAdminController::class)->prefix('servis')->whereNumber('id')->group(function () {
                    Route::get('/', 'index');
                    Route::post('/', 'store');
                    Route::get('/status-unit', 'statusUnit');
                    Route::put('/interval', 'interval');
                    Route::post('/{id}/selesai', 'selesai');
                    Route::post('/{id}/batal', 'batal');
                });

                Route::controller(PemesananAdminController::class)->prefix('pemesanan')->whereNumber('id')->group(function () {
                    Route::get('/', 'index');
                    Route::get('/{id}', 'show');
                    Route::put('/{id}/catatan', 'catatan');
                    Route::get('/{id}/unit-tersedia', 'unitTersedia');
                    Route::post('/{id}/serah-terima', 'serahTerima');
                    Route::post('/{id}/pengembalian/preview', 'previewPengembalian');
                    Route::post('/{id}/pengembalian', 'pengembalian');
                    Route::post('/{id}/setujui-pembatalan', 'setujuiPembatalan');
                    Route::post('/{id}/tolak-pembatalan', 'tolakPembatalan');
                    Route::post('/{id}/refund-selesai', 'refundSelesai');
                    Route::post('/{id}/tagihan/lunas', 'tagihanLunas');
                    Route::post('/{id}/jaminan/kembalikan', 'jaminanKembalikan');
                });
            });
        });
    });
});
