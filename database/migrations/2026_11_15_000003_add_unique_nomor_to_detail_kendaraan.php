<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks unik pada no_rangka, no_mesin, dan no_bpkb. NULL boleh banyak (unit tanpa nomor). Aplikasi sudah mengecek keunikan
 * (tanpa peka huruf besar kecil, tanpa spasi tepi) dan memberi 422 yang ramah; indeks ini menutup balapan dua permintaan
 * bersamaan. Sebelum indeks dibuat, nilai kosong dijadikan NULL dan data yang bentrok dilaporkan dengan jelas.
 */
return new class extends Migration
{
    private const KOLOM = ['no_rangka', 'no_mesin', 'no_bpkb'];

    public function up(): void
    {
        foreach (self::KOLOM as $kolom) {
            // Teks kosong bukan nomor: jadikan NULL agar tidak dianggap bentrok satu sama lain
            DB::table('detail_kendaraan')->whereNotNull($kolom)->whereRaw("TRIM({$kolom}) = ''")->update([$kolom => null]);
        }

        $bentrok = [];
        foreach (self::KOLOM as $kolom) {
            $baris = DB::table('detail_kendaraan')->whereNotNull($kolom)->groupBy($kolom)->havingRaw('COUNT(*) > 1')
                ->selectRaw("{$kolom} AS nilai, GROUP_CONCAT(id_kendaraan ORDER BY id_kendaraan) AS unit")->get();
            foreach ($baris as $b) {
                $bentrok[] = "{$kolom} \"{$b->nilai}\" dipakai unit {$b->unit}";
            }
        }
        if ($bentrok) {
            throw new RuntimeException("Indeks unik dibatalkan, ada nomor ganda: ".implode('; ', $bentrok).'. Koreksi atau kosongkan nomor itu pada salah satu unit, lalu jalankan migrasi lagi.');
        }

        Schema::table('detail_kendaraan', function (Blueprint $t) {
            foreach (self::KOLOM as $kolom) {
                $t->unique($kolom);
            }
        });
    }

    public function down(): void
    {
        Schema::table('detail_kendaraan', function (Blueprint $t) {
            foreach (self::KOLOM as $kolom) {
                $t->dropUnique([$kolom]);
            }
        });
    }
};
