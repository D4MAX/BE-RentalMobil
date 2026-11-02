<?php

use App\Http\Controllers\Api\PemesananController;
use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\KendaraanController;
use App\Http\Controllers\Api\ProfilController;
use App\Http\Middleware\EnsureRole;
use Illuminate\Support\Facades\Route;

// Publik
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/kendaraan', [KendaraanController::class, 'index']);
Route::get('/kendaraan/{id}', [KendaraanController::class, 'show']);

// Harus login
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profil', [ProfilController::class, 'show']);
    Route::put('/profil', [ProfilController::class, 'update']);

    // User: pemesanan
    Route::get('/pemesanan', [PemesananController::class, 'index']);
    Route::post('/pemesanan', [PemesananController::class, 'store']);
    Route::get('/pemesanan/{id}', [PemesananController::class, 'show']);
    Route::post('/pemesanan/{id}/batal', [PemesananController::class, 'batal']);
    Route::post('/pemesanan/{id}/review', [PemesananController::class, 'review']);

    // Admin
    Route::middleware(EnsureRole::class . ':admin')->prefix('admin')->group(function () {
        Route::get('/kendaraan', [Admin\KendaraanController::class, 'index']);
        Route::post('/kendaraan', [Admin\KendaraanController::class, 'store']);
        Route::get('/kendaraan/{id}', [Admin\KendaraanController::class, 'show']);
        Route::put('/kendaraan/{id}', [Admin\KendaraanController::class, 'update']);
        Route::delete('/kendaraan/{id}', [Admin\KendaraanController::class, 'destroy']);
    });
});