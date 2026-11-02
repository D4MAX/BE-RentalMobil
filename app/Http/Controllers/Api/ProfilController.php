<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ProfilController extends Controller
{
    public function show(Request $request)
    {
        return response()->json(['data' => $request->user()->load('pelanggan')]);
    }

    public function update(Request $request)
    {
        $pelanggan = $request->user()->pelanggan;

        if (! $pelanggan) {
            return response()->json(['message' => 'Akun ini tidak punya data profil pelanggan.'], 404);
        }

        $data = $request->validate([
            'nama' => 'sometimes|required|string|max:100',
            'no_hp' => 'sometimes|required|string|max:20',
            'no_identitas' => 'sometimes|required|string|max:30',
            'alamat' => 'sometimes|required|string',
        ]);

        $pelanggan->update($data);

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'data' => $pelanggan->fresh(),
        ]);
    }
}