<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft delete tipe dan unit: DELETE /admin/tipe dan /admin/unit hanya mengisi deleted_at (bisa dipulihkan dari tempat sampah).
 * Baris, foto, dan detail tidak dihapus; indeks unik (nama+merk, plat, no_rangka, no_mesin, no_bpkb) tetap berlaku untuk
 * baris di tempat sampah, sehingga bentrok dengannya ditolak aplikasi dengan kode *_ADA_DI_SAMPAH.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tipe_kendaraan', 'kendaraan'] as $tabel) {
            Schema::table($tabel, function (Blueprint $t) {
                $t->softDeletes()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['tipe_kendaraan', 'kendaraan'] as $tabel) {
            Schema::table($tabel, function (Blueprint $t) {
                $t->dropIndex(['deleted_at']);
                $t->dropSoftDeletes();
            });
        }
    }
};
