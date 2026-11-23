<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alamat dihapus dari profil pelanggan. Alamat antar tetap lewat pemesanan.lokasi_jemput; provinsi dan kota tetap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pelanggan', function (Blueprint $t) {
            $t->dropColumn('alamat');
        });
    }

    public function down(): void
    {
        Schema::table('pelanggan', function (Blueprint $t) {
            $t->text('alamat')->nullable()->after('no_identitas');
        });
    }
};
