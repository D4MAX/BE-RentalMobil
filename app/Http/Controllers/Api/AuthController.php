<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LupaPasswordRequest;
use App\Http\Requests\Api\RegisterRequest;
use App\Http\Requests\Api\ResetPasswordRequest;
use App\Http\Resources\AkunResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $akun = $this->auth->register($request->validated());

        return response()->json([
            'data' => ['id' => $akun->id_akun, 'nama' => $request->nama, 'email' => $akun->email],
            'message' => 'Cek email Anda untuk verifikasi',
        ], 201);
    }

    public function verifikasiEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string']]);
        $this->auth->verifikasiEmail($data['token']);

        return response()->json(['message' => 'Email berhasil diverifikasi']);
    }

    public function kirimUlangVerifikasi(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $this->auth->kirimUlangVerifikasi($data['email']);

        return response()->json(['message' => 'Tautan verifikasi dikirim']);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);

        return response()->json(['data' => $this->auth->login($data['email'], $data['password'])]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): AkunResource
    {
        return new AkunResource($request->user()->load('pelanggan'));
    }

    /** Selalu 200 dengan pesan yang sama, terdaftar atau tidak (anti enumerasi email). */
    public function lupaPassword(LupaPasswordRequest $request): JsonResponse
    {
        $this->auth->lupaPassword($request->validated()['email']);

        return response()->json(['message' => 'Jika email terdaftar, instruksi reset kata sandi telah dikirim']);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $d = $request->validated();
        $this->auth->resetPassword($d['email'], $d['token'], $d['password']);

        return response()->json(['message' => 'Kata sandi berhasil direset, silakan login']);
    }
}
