<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kendaraan;
use Illuminate\Http\Request;

class KendaraanController extends Controller
{
    private const KOLOM_PUBLIK = [
        'id_kendaraan', 'plat_nomor', 'merk', 'tipe', 'tahun', 'warna', 'harga_sewa_per_hari', 'status',
    ];

    public function index(Request $request)
    {
        $query = Kendaraan::select(self::KOLOM_PUBLIK);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($w) => $w->where('merk', 'like', "%{$q}%")->orWhere('tipe', 'like', "%{$q}%"));
        }

        return response()->json(['data' => $query->orderBy('merk')->get()]);
    }

    public function show(int $id)
    {
        $kendaraan = Kendaraan::select(self::KOLOM_PUBLIK)->findOrFail($id);

        return response()->json(['data' => $kendaraan]);
    }
}