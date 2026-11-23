<?php

namespace Tests\Concerns;

use App\Models\Akun;
use App\Models\Kendaraan;
use App\Models\Jaminan;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\SerahTerima;
use App\Models\TipeKendaraan;

/** Data uji pemesanan, dibuat langsung lewat model (bukan lewat API). */
trait BuatDataPemesanan
{
    /** @return array{0: Pelanggan, 1: string} pelanggan dan token Sanctum-nya */
    protected function buatPelanggan(string $email = 'budi@mail.test', string $kota = 'Kota Malang'): array
    {
        $akun = Akun::create(['email' => $email, 'password' => 'abc12345', 'email_terverifikasi_at' => now()]);
        $p = Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'Budi', 'no_hp' => '0812345678',
            'provinsi' => 'Jawa Timur', 'kota' => $kota]);

        return [$p, $akun->createToken('user', ['user'])->plainTextToken];
    }

    protected function buatTipe(int $unit = 1, string $nama = 'Brio'): TipeKendaraan
    {
        // nama + merk kini unik: pemanggilan berulang dengan nama yang sama diberi nomor urut (Brio, Brio 2, ...)
        $dasar = $nama;
        for ($n = 2; TipeKendaraan::where('nama', $nama)->where('merk', 'Honda')->exists(); $n++) {
            $nama = "{$dasar} {$n}";
        }
        $t = TipeKendaraan::create(['nama' => $nama, 'merk' => 'Honda', 'kursi' => 5, 'bahan_bakar' => 'bensin',
            'harga_sopir_per_hari' => 650000, 'harga_lepas_kunci_manual' => 300000, 'harga_lepas_kunci_matic' => 350000]);
        for ($i = 0; $i < $unit; $i++) {
            Kendaraan::create(['id_tipe' => $t->id_tipe, 'plat_nomor' => "N {$t->id_tipe}{$i} AB", 'tahun' => 2022,
                'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
        }

        return $t;
    }

    /** Pesanan lepas kunci 2 hari mulai 5 hari lagi, menunggu pembayaran (batas +30 menit), dengan satu baris pembayaran. */
    protected function buatPesanan(Pelanggan $p, TipeKendaraan $t, array $ubah = []): Pemesanan
    {
        $mulai = isset($ubah['tgl_mulai']) ? \Carbon\Carbon::parse($ubah['tgl_mulai']) : now()->addDays(5);
        $durasi = $ubah['durasi'] ?? 2;

        $pemesanan = Pemesanan::create($ubah + [
            'id_pelanggan' => $p->id_pelanggan, 'id_tipe' => $t->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual',
            'tgl_pesan' => now()->toDateString(), 'tgl_mulai' => $mulai->toDateString(), 'durasi' => $durasi,
            'tgl_kembali' => $mulai->copy()->addDays($durasi - 1)->toDateString(), 'metode_ambil' => 'ambil_di_kantor',
            'jam_serah' => '09:00', 'domisili' => 'malang_raya', 'setuju_syarat' => true,
            'total_biaya' => 300000 * $durasi, 'status' => 'menunggu_pembayaran', 'batas_pembayaran' => now()->addMinutes(30),
        ]);

        Pembayaran::create(['id_pemesanan' => $pemesanan->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => $pemesanan->total_biaya,
            'order_id_midtrans' => $pemesanan->nomor.'-1', 'status' => 'menunggu']);

        return $pemesanan->load('pembayaran');
    }

    /** @return array{0: Akun, 1: string} akun admin dan token Sanctum-nya */
    protected function buatAdmin(string $email = 'admin@mail.test', string $nama = 'Rina Admin'): array
    {
        $akun = Akun::create(['nama' => $nama, 'email' => $email, 'password' => 'abc12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);

        return [$akun, $akun->createToken('admin', ['admin'])->plainTextToken];
    }

    protected function lunasi(Pemesanan $p, string $metode = 'qris'): Pemesanan
    {
        $p->pembayaran->first()->update(['status' => 'lunas', 'metode' => $metode, 'tgl_bayar' => now()]);

        return $p;
    }

    /**
     * Pesanan berjalan: unit sudah diserahkan kemarin. Lepas kunci: jaminan ditahan, odometer awal 45800.
     * tgl_kembali rencana = hari ini, durasi 3 hari, total 900000 (tarif 300000 per hari).
     */
    protected function buatPesananBerjalan(Pelanggan $p, \App\Models\TipeKendaraan $t, array $ubah = []): Pemesanan
    {
        $unit = $t->kendaraan()->orderBy('id_kendaraan')->get()->first(fn ($u) => ! Pemesanan::where('id_kendaraan', $u->id_kendaraan)->where('status', 'berjalan')->exists());
        $kunci = ($ubah['jenis_sewa'] ?? 'lepas_kunci') === 'lepas_kunci';

        $pesanan = $this->buatPesanan($p, $t, $ubah + [
            'status' => 'berjalan', 'tgl_mulai' => now()->subDays(2)->toDateString(), 'durasi' => 3, 'id_kendaraan' => $unit->id_kendaraan,
        ]);
        $this->lunasi($pesanan);
        SerahTerima::create(['id_pemesanan' => $pesanan->id_pemesanan, 'id_kendaraan' => $unit->id_kendaraan, 'tgl_serah' => now()->subDay(),
            'odometer_awal' => $kunci ? 45800 : null, 'bbm_awal' => $kunci ? 'penuh' : null]);
        if ($kunci) {
            foreach (['ktp_asli', 'sim_c_asli', 'dokumen_tambahan:kk', 'motor_stnk'] as $jenis) {
                Jaminan::create(['id_pemesanan' => $pesanan->id_pemesanan, 'jenis' => $jenis, 'diterima' => true, 'dikembalikan' => false]);
            }
        }

        return $pesanan;
    }
}
