<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GantiPasswordRequest;
use App\Http\Requests\Api\ProfilRequest;
use App\Http\Resources\ProfilResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /profil (semua peran yang sudah login; ubah profil = POST multipart karena PHP tidak membaca PUT multipart) dan penggantian kata sandi. */
class ProfilController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function show(Request $request): ProfilResource
    {
        return new ProfilResource($request->user()->load('pelanggan'));
    }

    public function update(ProfilRequest $request): ProfilResource
    {
        return new ProfilResource($this->auth->ubahProfil(
            $request->user(),
            $request->safe()->except(['foto', 'hapus_foto']),
            $request->file('foto'),
            (bool) $request->validated('hapus_foto', false),
        ));
    }

    /** PUT /profil/password. */
    public function password(GantiPasswordRequest $request): JsonResponse
    {
        $d = $request->validated();
        $this->auth->gantiPassword($request->user(), $d['password_lama'], $d['password_baru']);

        return response()->json(['message' => 'Kata sandi berhasil diganti']);
    }
}
