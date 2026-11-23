<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\PerpanjangToken;
use Illuminate\Auth\Middleware\Authenticate;
use App\Http\Middleware\WajibGantiPassword;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'peran' => EnsureRole::class,
            'wajib_ganti_password' => WajibGantiPassword::class,
            'perpanjang_token' => PerpanjangToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, $e) => $request->is('api/*') || $request->expectsJson()
        );

        // Semua galat API memakai format {message, code, errors}
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Data yang dikirim tidak valid',
                    'code' => 'VALIDASI_GAGAL',
                    'errors' => $e->errors(),
                ], 422);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Belum login', 'code' => 'BELUM_LOGIN'], 401);
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Data tidak ditemukan', 'code' => 'TIDAK_DITEMUKAN'], 404);
            }
        });
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(
                    ['message' => 'Terlalu banyak percobaan, coba lagi nanti', 'code' => 'TERLALU_BANYAK_PERCOBAAN'],
                    429,
                    $e->getHeaders()
                );
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 429) {
                return response()->json(
                    ['message' => 'Terlalu banyak percobaan, coba lagi nanti', 'code' => 'TERLALU_BANYAK_PERCOBAAN'],
                    429,
                    $e->getHeaders()
                );
            }
        });
    })->create();
