<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto pengembalian kini per bagian mobil, supaya terlihat bagian mana yang rusak dan perlu diservis. Baris lama: null. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pengembalian_foto', function (Blueprint $t) {
            $t->enum('bagian', ['depan', 'belakang', 'sisi_kiri', 'sisi_kanan', 'atap', 'kaca_lampu', 'ban_velg', 'interior'])
                ->nullable()->after('id_pengembalian');
        });
    }

    public function down(): void
    {
        Schema::table('pengembalian_foto', function (Blueprint $t) {
            $t->dropColumn('bagian');
        });
    }
};
