<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admin dengan kata sandi sementara hanya boleh mengganti kata sandi (403 GANTI_PASSWORD_DULU). */
class WajibGantiPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->wajib_ganti_password) {
            throw new ApiException('Ganti kata sandi sementara Anda terlebih dahulu', 'GANTI_PASSWORD_DULU', 403);
        }

        return $next($request);
    }
}
