<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Akun;
use App\Models\Pelanggan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:50|unique:akun,username',
            'password' => 'required|string|min:6|confirmed',
            'nama' => 'required|string|max:100',
            'no_hp' => 'required|string|max:20',
            'no_identitas' => 'required|string|max:30',
            'alamat' => 'required|string',
        ], [
            'username.unique' => 'Username sudah dipakai.',
        ]);

        $akun = DB::transaction(function () use ($data) {
            $akun = Akun::create([
                'username' => $data['username'],
                'password' => $data['password'],
                'role' => 'user',
            ]);

            Pelanggan::create([
                'id_akun' => $akun->id_akun,
                'nama' => $data['nama'],
                'no_hp' => $data['no_hp'],
                'no_identitas' => $data['no_identitas'],
                'alamat' => $data['alamat'],
            ]);

            return $akun;
        });

        return response()->json([
            'message' => 'Registrasi berhasil, silakan login.',
            'data' => $akun->load('pelanggan'),
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $akun = Akun::where('username', $data['username'])->first();

        if (! $akun || ! Hash::check($data['password'], $akun->password)) {
            throw ValidationException::withMessages([
                'username' => 'Username atau password salah.',
            ]);
        }

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $akun->createToken('api')->plainTextToken,
            'role' => $akun->role,
            'data' => $akun->load('pelanggan'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }
}