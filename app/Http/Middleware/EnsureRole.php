<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $akun = $request->user();

        if (! $akun || $akun->role !== $role) {
            throw new ApiException('Anda tidak punya akses ke fitur ini', 'AKSES_DITOLAK', 403);
        }

        return $next($request);
    }
}
