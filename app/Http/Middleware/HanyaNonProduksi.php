<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman dokumentasi (Swagger UI) tidak boleh hidup di production: 404 seolah-olah tidak ada. */
class HanyaNonProduksi
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(app()->environment('production'), 404);

        return $next($request);
    }
}
