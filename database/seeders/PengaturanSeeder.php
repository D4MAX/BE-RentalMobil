<?php

namespace Database\Seeders;

use App\Models\Pengaturan;
use Illuminate\Database\Seeder;

class PengaturanSeeder extends Seeder
{
    public function run(): void
    {
        // Nilai awal; data resmi dari pusat belum ada (lihat PRD bagian 14). Hanya mengisi yang belum ada.
        $awal = [
            'jam_operasional' => '07.00-21.00',
            'batas_antar_gratis' => 'Tlogomas',
            'alamat' => null,
            'telepon' => null,
            'email' => null,
            'jumlah_sopir' => null, // kosong = tidak dibatasi
            'interval_km' => (string) config('rental.interval_km'),
        ];

        foreach ($awal as $kunci => $nilai) {
            Pengaturan::firstOrCreate(['kunci' => $kunci], ['nilai' => $nilai]);
        }
    }
}
