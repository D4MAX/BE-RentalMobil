<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu pesanan menghasilkan paling banyak satu servis dari pengembalian: id_pemesanan_asal unik (NULL boleh banyak, untuk
 * servis manual). Menjaga taksiran kerusakan pesanan tidak terhitung dua kali di biaya_ditagih_ke_penyewa. Data lama tidak
 * diubah: bila ada duplikat, migrasi dibatalkan dan duplikatnya dilaporkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ganda = DB::table('perawatan')->whereNotNull('id_pemesanan_asal')->groupBy('id_pemesanan_asal')->havingRaw('COUNT(*) > 1')
            ->selectRaw('id_pemesanan_asal, GROUP_CONCAT(id_perawatan ORDER BY id_perawatan) AS servis')->get();
        if ($ganda->isNotEmpty()) {
            $daftar = $ganda->map(fn ($b) => "pesanan {$b->id_pemesanan_asal} punya servis {$b->servis}")->implode('; ');
            throw new RuntimeException("Indeks unik dibatalkan, ada servis ganda dari satu pesanan: {$daftar}. Putuskan servis mana yang dipertahankan, lalu jalankan migrasi lagi.");
        }

        Schema::table('perawatan', fn (Blueprint $t) => $t->unique('id_pemesanan_asal'));
    }

    public function down(): void
    {
        Schema::table('perawatan', fn (Blueprint $t) => $t->dropUnique(['id_pemesanan_asal']));
    }
};
