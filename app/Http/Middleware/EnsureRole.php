<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $akun = $request->user();

        if (! $akun || $akun->role !== $role) {
            return response()->json(['message' => 'Anda tidak punya akses ke fitur ini.'], 403);
        }

        return $next($request);
    }
}