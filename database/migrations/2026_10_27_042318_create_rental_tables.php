<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema mengikuti ERD V22 (PRD V8, Spek API 1.4): 20 tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('akun', function (Blueprint $t) {
            $t->id('id_akun');
            $t->string('nama', 100)->nullable(); // dipakai admin; nama user ada di pelanggan
            $t->string('email')->unique();
            $t->string('password');
            $t->enum('role', ['admin', 'user'])->default('user');
            $t->boolean('aktif')->default(true);
            $t->timestamp('email_terverifikasi_at')->nullable();
            $t->boolean('wajib_ganti_password')->default(false);
            $t->timestamps();
        });

        Schema::create('token_akun', function (Blueprint $t) {
            $t->id('id_token');
            $t->foreignId('id_akun')->constrained('akun', 'id_akun')->cascadeOnDelete();
            $t->enum('jenis', ['verifikasi_email', 'reset_password']);
            $t->string('token_hash', 64)->index();
            $t->timestamp('kedaluwarsa_at');
            $t->timestamp('dipakai_at')->nullable();
            $t->timestamps();
        });

        Schema::create('log_aktivitas', function (Blueprint $t) {
            $t->id('id_log');
            $t->foreignId('id_akun')->nullable()->constrained('akun', 'id_akun')->nullOnDelete(); // null = Sistem
            $t->string('pelaku', 20)->default('admin'); // admin, sistem, pelanggan
            $t->string('aksi');
            $t->string('objek', 50)->nullable();
            $t->unsignedBigInteger('id_objek')->nullable();
            $t->timestamp('waktu')->useCurrent();
            $t->index(['objek', 'id_objek']);
        });

        Schema::create('pelanggan', function (Blueprint $t) {
            $t->id('id_pelanggan');
            $t->foreignId('id_akun')->unique()->constrained('akun', 'id_akun')->cascadeOnDelete();
            $t->string('nama', 100);
            $t->string('no_hp', 20);
            $t->string('no_identitas', 30)->nullable(); // diisi admin saat serah terima
            $t->text('alamat')->nullable();
            $t->string('provinsi', 100);
            $t->string('kota', 100);
            $t->string('foto')->nullable();
            $t->timestamps();
        });

        Schema::create('tipe_kendaraan', function (Blueprint $t) {
            $t->id('id_tipe');
            $t->string('nama', 100);
            $t->string('merk', 100);
            $t->unsignedTinyInteger('kursi');
            $t->enum('bahan_bakar', ['bensin', 'diesel', 'listrik']);
            $t->unsignedBigInteger('harga_sopir_per_hari')->nullable();
            $t->unsignedBigInteger('harga_lepas_kunci_manual')->nullable();
            $t->unsignedBigInteger('harga_lepas_kunci_matic')->nullable();
            $t->timestamps();
        });

        Schema::create('tipe_foto', function (Blueprint $t) {
            $t->id('id_foto');
            $t->foreignId('id_tipe')->constrained('tipe_kendaraan', 'id_tipe')->cascadeOnDelete();
            $t->string('path_foto');
            $t->unsignedTinyInteger('urutan')->default(1);
            $t->timestamps();
        });

        Schema::create('kendaraan', function (Blueprint $t) {
            $t->id('id_kendaraan');
            $t->foreignId('id_tipe')->constrained('tipe_kendaraan', 'id_tipe');
            $t->string('plat_nomor', 15)->unique();
            $t->unsignedSmallInteger('tahun');
            $t->string('warna', 30);
            $t->enum('transmisi', ['manual', 'matic']);
            $t->unsignedInteger('km_terakhir')->default(0);
            // "disewa" tidak disimpan: dihitung dari pesanan berstatus berjalan
            $t->enum('status', ['tersedia', 'dalam_perawatan', 'tidak_tersedia'])->default('tersedia');
            $t->timestamps();
        });

        Schema::create('detail_kendaraan', function (Blueprint $t) {
            $t->id('id_detail_kendaraan');
            $t->foreignId('id_kendaraan')->unique()->constrained('kendaraan', 'id_kendaraan')->cascadeOnDelete();
            $t->string('nama_pemilik')->nullable();
            $t->enum('status_kepemilikan', ['milik_perusahaan', 'titipan'])->default('milik_perusahaan');
            $t->string('no_rangka', 50)->nullable();
            $t->string('no_mesin', 50)->nullable();
            $t->string('no_bpkb', 50)->nullable();
            $t->date('masa_berlaku_stnk')->nullable();
            $t->text('catatan')->nullable();
            $t->timestamps();
        });

        Schema::create('pemesanan', function (Blueprint $t) {
            $t->id('id_pemesanan');
            $t->foreignId('id_pelanggan')->constrained('pelanggan', 'id_pelanggan');
            $t->foreignId('id_tipe')->constrained('tipe_kendaraan', 'id_tipe');
            $t->foreignId('id_kendaraan')->nullable()->constrained('kendaraan', 'id_kendaraan'); // diisi saat serah terima
            $t->foreignId('id_admin')->nullable()->constrained('akun', 'id_akun')->nullOnDelete();
            $t->enum('jenis_sewa', ['dengan_sopir', 'lepas_kunci']);
            $t->enum('transmisi', ['manual', 'matic'])->nullable();
            $t->date('tgl_pesan');
            $t->date('tgl_mulai');
            $t->unsignedTinyInteger('durasi');
            $t->date('tgl_kembali'); // tgl_mulai + durasi - 1 (disimpan untuk query irisan tanggal)
            $t->enum('metode_ambil', ['ambil_di_kantor', 'diantar'])->nullable();
            $t->time('jam_serah');
            $t->string('lokasi_jemput')->nullable();
            $t->text('tujuan')->nullable();
            $t->enum('domisili', ['malang_raya', 'luar_malang_raya']);
            $t->text('catatan')->nullable();
            $t->text('catatan_admin')->nullable();
            $t->boolean('setuju_syarat')->default(false);
            $t->unsignedBigInteger('total_biaya');
            $t->enum('status', ['menunggu_pembayaran', 'dikonfirmasi', 'berjalan', 'selesai', 'pengajuan_pembatalan', 'dibatalkan'])
                ->default('menunggu_pembayaran');
            $t->string('status_sebelumnya', 30)->nullable();
            $t->string('alasan_pembatalan')->nullable();
            $t->timestamp('batas_pembayaran')->nullable();
            $t->timestamps();
            $t->index(['id_tipe', 'tgl_mulai', 'tgl_kembali']);
        });

        Schema::create('pembayaran', function (Blueprint $t) {
            $t->id('id_pembayaran');
            $t->foreignId('id_pemesanan')->constrained('pemesanan', 'id_pemesanan');
            $t->enum('jenis', ['sewa', 'tambahan', 'refund'])->default('sewa');
            $t->string('metode', 50)->nullable();
            $t->unsignedBigInteger('jumlah');
            $t->string('order_id_midtrans')->nullable()->unique();
            $t->dateTime('tgl_bayar')->nullable();
            $t->enum('status', ['menunggu', 'lunas', 'gagal', 'dikembalikan'])->default('menunggu');
            // data refund virtual account
            $t->string('bank', 50)->nullable();
            $t->string('no_rekening', 50)->nullable();
            $t->string('nama_rekening', 100)->nullable();
            $t->enum('refund_cara', ['midtrans', 'transfer_admin'])->nullable();
            $t->boolean('refund_selesai')->default(false);
            $t->timestamps();
        });

        Schema::create('serah_terima', function (Blueprint $t) {
            $t->id('id_serah_terima');
            $t->foreignId('id_pemesanan')->unique()->constrained('pemesanan', 'id_pemesanan');
            $t->foreignId('id_kendaraan')->constrained('kendaraan', 'id_kendaraan');
            $t->foreignId('id_admin')->nullable()->constrained('akun', 'id_akun')->nullOnDelete();
            $t->dateTime('tgl_serah');
            $t->unsignedInteger('odometer_awal')->nullable();
            $t->string('bbm_awal', 20)->nullable();
            $t->text('catatan_kondisi')->nullable();
            $t->json('foto_kondisi')->nullable();
            $t->timestamps();
        });

        Schema::create('jaminan', function (Blueprint $t) {
            $t->id('id_jaminan');
            $t->foreignId('id_pemesanan')->constrained('pemesanan', 'id_pemesanan');
            $t->string('jenis', 50);
            $t->boolean('diterima')->default(true);
            $t->boolean('dikembalikan')->default(false);
            $t->timestamps();
        });

        Schema::create('pengembalian', function (Blueprint $t) {
            $t->id('id_pengembalian');
            $t->foreignId('id_pemesanan')->unique()->constrained('pemesanan', 'id_pemesanan');
            $t->date('tgl_kembali');
            $t->time('jam_kembali');
            $t->unsignedBigInteger('tarif_denda_per_hari')->default(0);
            $t->unsignedInteger('hari_terlambat')->default(0);
            $t->unsignedBigInteger('denda_keterlambatan')->default(0);
            $t->unsignedInteger('odometer_akhir')->nullable();
            $t->string('bbm_akhir', 20)->nullable();
            $t->unsignedBigInteger('biaya_kekurangan_bbm')->default(0);
            $t->string('kebersihan', 20)->nullable();
            $t->text('catatan_admin')->nullable();
            $t->boolean('disetujui_penyewa')->default(false);
            $t->timestamps();
        });

        Schema::create('pengembalian_kondisi', function (Blueprint $t) {
            $t->id('id_kondisi');
            $t->foreignId('id_pengembalian')->constrained('pengembalian', 'id_pengembalian')->cascadeOnDelete();
            $t->enum('bagian', ['depan', 'belakang', 'sisi_kiri', 'sisi_kanan', 'atap', 'kaca_lampu', 'ban_velg', 'interior']);
            $t->enum('kondisi', ['baik', 'lecet', 'penyok', 'retak', 'rusak']);
            $t->unsignedBigInteger('biaya')->default(0);
            $t->string('keterangan')->nullable();
            $t->timestamps();
        });

        Schema::create('pengembalian_foto', function (Blueprint $t) {
            $t->id('id_foto');
            $t->foreignId('id_pengembalian')->constrained('pengembalian', 'id_pengembalian')->cascadeOnDelete();
            $t->string('path_foto');
            $t->timestamps();
        });

        Schema::create('biaya_tambahan', function (Blueprint $t) {
            $t->id('id_biaya');
            $t->foreignId('id_pengembalian')->constrained('pengembalian', 'id_pengembalian')->cascadeOnDelete();
            $t->enum('jenis', ['cuci', 'kelengkapan_hilang', 'tol', 'parkir', 'biaya_antar', 'lainnya']);
            $t->string('keterangan')->nullable();
            $t->unsignedBigInteger('nominal');
            $t->timestamps();
        });

        Schema::create('review', function (Blueprint $t) {
            $t->id('id_review');
            $t->foreignId('id_pemesanan')->unique()->constrained('pemesanan', 'id_pemesanan');
            $t->unsignedTinyInteger('rating');
            $t->text('komentar')->nullable();
            $t->date('tgl_review');
            $t->boolean('tampil_di_katalog')->default(false);
            $t->timestamps();
        });

        Schema::create('perawatan', function (Blueprint $t) {
            $t->id('id_perawatan');
            $t->foreignId('id_kendaraan')->constrained('kendaraan', 'id_kendaraan');
            $t->foreignId('id_pemesanan_asal')->nullable()->constrained('pemesanan', 'id_pemesanan');
            $t->string('jenis_servis', 100);
            $t->enum('tingkat_servis', ['ringan', 'sedang', 'perlu_bengkel']);
            $t->date('tgl_perawatan');
            $t->unsignedInteger('km_saat_ini')->nullable();
            $t->string('bengkel')->nullable();
            $t->unsignedBigInteger('biaya_bengkel')->default(0); // estimasi saat mulai, biaya akhir saat selesai
            $t->unsignedBigInteger('biaya_ditagih_ke_penyewa')->default(0);
            $t->text('catatan')->nullable();
            $t->enum('status', ['berlangsung', 'selesai', 'dibatalkan'])->default('berlangsung');
            $t->unsignedInteger('km_berikutnya')->nullable();
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

        Schema::create('pengaturan', function (Blueprint $t) {
            $t->string('kunci', 100)->primary();
            $t->text('nilai')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'pengaturan', 'detail_perawatan', 'perawatan', 'review', 'biaya_tambahan', 'pengembalian_foto',
            'pengembalian_kondisi', 'pengembalian', 'jaminan', 'serah_terima', 'pembayaran', 'pemesanan',
            'detail_kendaraan', 'kendaraan', 'tipe_foto', 'tipe_kendaraan', 'pelanggan', 'log_aktivitas',
            'token_akun', 'akun',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
