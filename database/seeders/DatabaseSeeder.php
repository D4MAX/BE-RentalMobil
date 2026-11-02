<?php

namespace Database\Seeders;

use App\Models\Akun;
use App\Models\Kendaraan;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Akun::firstOrCreate(
            ['username' => 'admin'],
            ['password' => 'admin123', 'role' => 'admin']
        );

        $contoh = [
            ['B 1234 ABC', 'Toyota', 'Avanza', 2022, 'Hitam', 350000, 15000],
            ['N 5678 XY', 'Honda', 'Brio', 2023, 'Putih', 300000, 8000],
            ['AG 9012 CD', 'Daihatsu', 'Xenia', 2021, 'Silver', 320000, 30000],
        ];

        foreach ($contoh as [$plat, $merk, $tipe, $tahun, $warna, $harga, $km]) {
            $k = Kendaraan::firstOrCreate(['plat_nomor' => $plat], [
                'merk' => $merk, 'tipe' => $tipe, 'tahun' => $tahun, 'warna' => $warna,
                'harga_sewa_per_hari' => $harga, 'km_terakhir' => $km, 'status' => 'tersedia',
            ]);

            $k->detail()->firstOrCreate([], [
                'nama_pemilik' => 'PT Rental Contoh',
                'status_kepemilikan' => 'milik perusahaan',
                'no_rangka' => 'RK' . str_pad((string) $k->id_kendaraan, 8, '0', STR_PAD_LEFT),
                'no_mesin' => 'MS' . str_pad((string) $k->id_kendaraan, 8, '0', STR_PAD_LEFT),
                'no_bpkb' => 'BPKB' . str_pad((string) $k->id_kendaraan, 6, '0', STR_PAD_LEFT),
                'masa_berlaku_stnk' => now()->addYear()->toDateString(),
                'tgl_pembelian' => now()->subYears(2)->toDateString(),
                'harga_beli' => 200000000,
            ]);
        }
    }
}