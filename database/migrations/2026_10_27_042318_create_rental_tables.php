<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akun', function (Blueprint $t) {
            $t->id('id_akun');
            $t->string('username')->unique();
            $t->string('password');
            $t->enum('role', ['admin', 'user'])->default('user');
            $t->timestamps();
        });

        Schema::create('pelanggan', function (Blueprint $t) {
            $t->id('id_pelanggan');
            $t->foreignId('id_akun')->constrained('akun', 'id_akun')->cascadeOnDelete();
            $t->string('nama');
            $t->string('no_hp', 20);
            $t->string('no_identitas', 30);
            $t->text('alamat');
            $t->timestamps();
        });

        Schema::create('kendaraan', function (Blueprint $t) {
            $t->id('id_kendaraan');
            $t->string('plat_nomor', 15)->unique();
            $t->string('merk');
            $t->string('tipe');
            $t->unsignedSmallInteger('tahun');
            $t->string('warna', 30);
            $t->unsignedBigInteger('harga_sewa_per_hari');
            $t->unsignedInteger('km_terakhir')->default(0);
            $t->enum('status', ['tersedia', 'disewa', 'dalam_perawatan'])->default('tersedia');
            $t->timestamps();
        });

        Schema::create('detail_kendaraan', function (Blueprint $t) {
            $t->id('id_detail_kendaraan');
            $t->foreignId('id_kendaraan')->unique()->constrained('kendaraan', 'id_kendaraan')->cascadeOnDelete();
            $t->string('nama_pemilik');
            $t->string('status_kepemilikan', 50);
            $t->string('no_rangka', 50);
            $t->string('no_mesin', 50);
            $t->string('no_bpkb', 50);
            $t->date('masa_berlaku_stnk');
            $t->date('tgl_pembelian');
            $t->unsignedBigInteger('harga_beli');
            $t->text('catatan')->nullable();
            $t->timestamps();
        });

        Schema::create('pemesanan', function (Blueprint $t) {
            $t->id('id_pemesanan');
            $t->foreignId('id_pelanggan')->constrained('pelanggan', 'id_pelanggan');
            $t->foreignId('id_kendaraan')->constrained('kendaraan', 'id_kendaraan');
            $t->foreignId('id_admin')->nullable()->constrained('akun', 'id_akun')->nullOnDelete();
            $t->enum('jenis_pesanan', ['online', 'manual'])->default('online');
            $t->date('tgl_pesan');
            $t->date('tgl_mulai');
            $t->date('tgl_selesai');
            $t->unsignedBigInteger('total_biaya');
            $t->enum('status', ['menunggu', 'dikonfirmasi', 'pengajuan_batal', 'selesai', 'dibatalkan'])->default('menunggu');
            $t->timestamps();
        });

        Schema::create('pembayaran', function (Blueprint $t) {
            $t->id('id_pembayaran');
            $t->foreignId('id_pemesanan')->unique()->constrained('pemesanan', 'id_pemesanan');
            $t->string('no_pembayaran')->unique();
            $t->string('metode', 50);
            $t->unsignedBigInteger('jumlah');
            $t->dateTime('tgl_bayar')->nullable();
            $t->string('status', 30)->default('menunggu'); // menunggu, lunas, dikembalikan
            $t->timestamps();
        });

        Schema::create('pengembalian', function (Blueprint $t) {
            $t->id('id_pengembalian');
            $t->foreignId('id_pemesanan')->unique()->constrained('pemesanan', 'id_pemesanan');
            $t->date('tgl_kembali');
            $t->text('kondisi_mobil');
            $t->unsignedInteger('hari_terlambat')->default(0);
            $t->unsignedBigInteger('denda_keterlambatan')->default(0);
            $t->unsignedBigInteger('denda_kerusakan')->default(0);
            $t->unsignedBigInteger('biaya_tambahan')->default(0);
            $t->unsignedBigInteger('total_denda')->default(0);
            $t->timestamps();
        });

        Schema::create('review', function (Blueprint $t) {
            $t->id('id_review');
            $t->foreignId('id_pemesanan')->unique()->constrained('pemesanan', 'id_pemesanan');
            $t->unsignedTinyInteger('rating');
            $t->text('komentar')->nullable();
            $t->date('tgl_review');
            $t->timestamps();
        });

        Schema::create('perawatan', function (Blueprint $t) {
            $t->id('id_perawatan');
            $t->foreignId('id_kendaraan')->constrained('kendaraan', 'id_kendaraan');
            $t->string('jenis_servis', 100);
            $t->enum('tingkat_servis', ['ringan', 'sedang', 'berat']);
            $t->date('tgl_perawatan');                      // tanggal masuk
            $t->unsignedInteger('km_saat_ini');             // odometer
            $t->string('bengkel');
            $t->unsignedBigInteger('biaya')->default(0);    // estimasi, jadi biaya akhir saat selesai
            $t->text('catatan')->nullable();
            $t->enum('status', ['berlangsung', 'selesai', 'dibatalkan'])->default('berlangsung');
            $t->unsignedInteger('km_berikutnya')->nullable();   // dihitung saat servis selesai
            $t->date('tgl_berikutnya')->nullable();
            $t->timestamps();
        });

        Schema::create('detail_perawatan', function (Blueprint $t) {
            $t->id('id_detail');
            $t->foreignId('id_perawatan')->constrained('perawatan', 'id_perawatan')->cascadeOnDelete();
            $t->string('komponen');
            $t->string('keterangan')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['detail_perawatan', 'perawatan', 'review', 'pengembalian', 'pembayaran', 'pemesanan',
                  'detail_kendaraan', 'kendaraan', 'pelanggan', 'akun'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};