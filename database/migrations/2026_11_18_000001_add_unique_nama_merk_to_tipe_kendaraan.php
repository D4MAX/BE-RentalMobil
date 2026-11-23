<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks unik (nama, merk) pada tipe_kendaraan. Perbandingan MySQL tidak peka huruf besar kecil (collation
 * utf8mb4_unicode_ci). Aplikasi merapikan nama dan memeriksa duplikat lebih dulu dengan TIPE_SUDAH_ADA; indeks ini menutup
 * balapan dua permintaan bersamaan. Data lama tidak diubah: bila ada duplikat, migrasi berhenti dengan pesan yang jelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ganda = DB::table('tipe_kendaraan')->groupBy('nama', 'merk')->havingRaw('COUNT(*) > 1')
            ->selectRaw('nama, merk, GROUP_CONCAT(id_tipe ORDER BY id_tipe) AS id')->get();

        if ($ganda->isNotEmpty()) {
            $daftar = $ganda->map(fn ($g) => "\"{$g->nama}\" merk \"{$g->merk}\" (tipe {$g->id})")->implode('; ');

            throw new RuntimeException("Indeks unik tipe dibatalkan, ada nama dan merk ganda: {$daftar}. Ubah atau hapus salah satunya, lalu jalankan migrasi lagi.");
        }

        Schema::table('tipe_kendaraan', function (Blueprint $t) {
            $t->unique(['nama', 'merk']);
        });
    }

    public function down(): void
    {
        Schema::table('tipe_kendaraan', function (Blueprint $t) {
            $t->dropUnique(['nama', 'merk']);
        });
    }
};
