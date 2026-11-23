<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * alasan_pembatalan hanya berisi kode sistem (kedaluwarsa, pembayaran_gagal, dibatalkan_pelanggan, tidak_diambil,
 * pengajuan_disetujui). Alasan bebas yang diketik pelanggan saat mengajukan pembatalan pindah ke alasan_pengajuan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pemesanan', function (Blueprint $t) {
            $t->text('alasan_pengajuan')->nullable()->after('alasan_pembatalan');
        });

        // Pengajuan yang sudah tercatat memakai kolom lama untuk teks bebas: pindahkan
        DB::table('pemesanan')->where('status', 'pengajuan_pembatalan')->whereNotNull('alasan_pembatalan')
            ->update(['alasan_pengajuan' => DB::raw('alasan_pembatalan'), 'alasan_pembatalan' => null]);
    }

    public function down(): void
    {
        // Teks bebas dikembalikan ke kolom lama hanya untuk pengajuan yang masih berjalan
        DB::table('pemesanan')->where('status', 'pengajuan_pembatalan')->whereNotNull('alasan_pengajuan')
            ->update(['alasan_pembatalan' => DB::raw('alasan_pengajuan')]);

        Schema::table('pemesanan', function (Blueprint $t) {
            $t->dropColumn('alasan_pengajuan');
        });
    }
};
