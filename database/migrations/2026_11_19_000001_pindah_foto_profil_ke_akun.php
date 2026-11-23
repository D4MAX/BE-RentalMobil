<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Foto profil dipindah ke tabel akun supaya admin (yang tidak punya baris pelanggan) juga bisa punya satu foto.
 * Kolom pelanggan.foto lama tidak pernah diisi lewat API; nilai yang ada (bila ada) disalin dulu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('akun', function (Blueprint $t) {
            $t->string('foto', 255)->nullable()->after('email');
        });

        DB::statement('UPDATE akun JOIN pelanggan ON pelanggan.id_akun = akun.id_akun SET akun.foto = pelanggan.foto WHERE pelanggan.foto IS NOT NULL');

        Schema::table('pelanggan', function (Blueprint $t) {
            $t->dropColumn('foto');
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $t) {
            $t->string('foto')->nullable()->after('kota');
        });

        DB::statement('UPDATE pelanggan JOIN akun ON pelanggan.id_akun = akun.id_akun SET pelanggan.foto = akun.foto');

        Schema::table('akun', function (Blueprint $t) {
            $t->dropColumn('foto');
        });
    }
};
