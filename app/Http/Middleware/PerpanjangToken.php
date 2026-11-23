<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle timeout: permintaan terautentikasi yang BERHASIL (status di bawah 400) memperpanjang expires_at token yang dipakai sampai
 * masa berlaku penuh menurut peran (Akun::masaTokenMenit). Query hanya terjadi bila sisa waktu tinggal separuh atau kurang, jadi
 * paling banyak satu UPDATE per setengah masa berlaku, bukan per permintaan. Tidak berjalan untuk permintaan gagal (4xx, 5xx),
 * untuk token tanpa expires_at, atau token yang sudah dihapus (logout). Token yang sudah kedaluwarsa tidak pernah sampai sini:
 * guard Sanctum menolaknya lebih dulu dengan 401 BELUM_LOGIN.
 */
class PerpanjangToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $this->perpanjang($request);
        }

        return $response;
    }

    private function perpanjang(Request $request): void
    {
        $akun = $request->user();
        $token = $akun?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->exists || $token->expires_at === null || $token->expires_at->isPast()) {
            return;
        }

        $penuh = $akun->masaTokenMenit();

        if (now()->diffInSeconds($token->expires_at, false) <= $penuh * 30) { // sisa <= separuh masa berlaku
            $token->forceFill(['expires_at' => now()->addMinutes($penuh)])->save();
        }
    }
}
