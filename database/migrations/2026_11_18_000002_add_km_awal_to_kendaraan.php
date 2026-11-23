<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * km_awal: kilometer saat unit diinput, patokan servis berkala untuk unit yang belum pernah diservis (bersama created_at).
 * Unit lama diisi dari km_terakhir sekarang (kilometer saat input yang sebenarnya sudah tidak tersimpan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kendaraan', function (Blueprint $t) {
            $t->unsignedInteger('km_awal')->nullable()->after('km_terakhir');
        });

        DB::table('kendaraan')->whereNull('km_awal')->update(['km_awal' => DB::raw('km_terakhir')]);
    }

    public function down(): void
    {
        Schema::table('kendaraan', function (Blueprint $t) {
            $t->dropColumn('km_awal');
        });
    }
};
