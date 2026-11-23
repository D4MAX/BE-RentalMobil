<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bahan_bakar tipe kendaraan: hanya bensin dan diesel ("listrik" dihapus dari enum).
 *
 * Data tidak pernah dihapus atau diubah di sini. Bila masih ada tipe berbahan bakar listrik (termasuk yang di tempat sampah),
 * migrasi berhenti dengan daftar tipenya, karena MySQL tidak bisa mempersempit enum selama nilai itu masih dipakai.
 * Ubah bahan bakar tipe itu (atau hapus permanen) secara manual, lalu jalankan migrate lagi.
 */
return new class extends Migration
{
    public function up(): void
    {
        // DB::table tidak memakai soft delete: tipe di tempat sampah ikut terhitung
        $listrik = DB::table('tipe_kendaraan')->where('bahan_bakar', 'listrik')->orderBy('id_tipe')->get(['id_tipe', 'merk', 'nama', 'deleted_at']);

        if ($listrik->isNotEmpty()) {
            $daftar = $listrik->map(fn ($t) => "#{$t->id_tipe} {$t->merk} {$t->nama}".($t->deleted_at ? ' (di tempat sampah)' : ''))->implode(', ');

            throw new RuntimeException(
                "Migrasi dihentikan: masih ada {$listrik->count()} tipe berbahan bakar \"listrik\": {$daftar}. Data tidak diubah. "
                .'Ubah bahan_bakar tipe itu menjadi bensin atau diesel (atau hapus permanen tipenya), lalu jalankan migrate lagi.'
            );
        }

        Schema::table('tipe_kendaraan', function (Blueprint $t) {
            $t->enum('bahan_bakar', ['bensin', 'diesel'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('tipe_kendaraan', function (Blueprint $t) {
            $t->enum('bahan_bakar', ['bensin', 'diesel', 'listrik'])->change();
        });
    }
};
