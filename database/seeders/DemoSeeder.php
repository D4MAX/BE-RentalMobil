<?php

namespace Database\Seeders;

use App\Models\Kendaraan;
use App\Models\TipeKendaraan;
use Illuminate\Database\Seeder;

/** Data demo tipe dan unit. Tarif dan jumlah unit hanya contoh. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $tipe = [
            ['Brio', 'Honda', 5, 'bensin', 650000, 300000, 350000, [['N 1234 AB', 2023, 'Putih', 'matic'], ['N 1235 AB', 2022, 'Merah', 'manual']]],
            ['Avanza', 'Toyota', 7, 'bensin', 750000, 350000, 400000, [['N 2345 CD', 2022, 'Hitam', 'manual'], ['N 2346 CD', 2023, 'Silver', 'matic']]],
            ['Xenia', 'Daihatsu', 7, 'bensin', 700000, 330000, 380000, [['N 3456 EF', 2021, 'Silver', 'manual']]],
        ];

        foreach ($tipe as [$nama, $merk, $kursi, $bbm, $sopir, $manual, $matic, $unit]) {
            $t = TipeKendaraan::withTrashed()->firstOrCreate(['nama' => $nama, 'merk' => $merk], [
                'kursi' => $kursi, 'bahan_bakar' => $bbm,
                'harga_sopir_per_hari' => $sopir,
                'harga_lepas_kunci_manual' => $manual,
                'harga_lepas_kunci_matic' => $matic,
            ]);
            $t->trashed() && $t->restore(); // data demo yang sempat masuk tempat sampah dikembalikan, bukan diduplikasi

            foreach ($unit as [$plat, $tahun, $warna, $transmisi]) {
                $k = Kendaraan::withTrashed()->firstOrCreate(['plat_nomor' => $plat], [
                    'id_tipe' => $t->id_tipe, 'tahun' => $tahun, 'warna' => $warna,
                    'transmisi' => $transmisi, 'km_terakhir' => 10000, 'status' => 'tersedia',
                ]);
                $k->trashed() && $k->restore();
                $kode = preg_replace('/\W+/', '', $plat); // nomor rangka dan mesin wajib dan unik: turunkan dari plat
                $k->detail()->firstOrCreate([], [
                    'nama_pemilik' => 'PT Rental Contoh',
                    'status_kepemilikan' => 'milik_perusahaan',
                    'no_rangka' => "MHDEMO{$kode}",
                    'no_mesin' => "ENDEMO{$kode}",
                    'masa_berlaku_stnk' => now()->addYear()->toDateString(),
                ]);
            }
        }
    }
}
