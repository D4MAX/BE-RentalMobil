<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Foto bukti tiap item jaminan fisik saat serah terima lepas kunci; disimpan di penyimpanan privat (disk local). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jaminan', function (Blueprint $t) {
            $t->string('foto', 255)->nullable()->after('jenis');
        });
    }

    public function down(): void
    {
        Schema::table('jaminan', function (Blueprint $t) {
            $t->dropColumn('foto');
        });
    }
};
