<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Jaminan;
use App\Models\Kendaraan;
use App\Models\LogAktivitas;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\SerahTerima;
use App\Models\TipeKendaraan;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Permintaan bersamaan (Spek API bagian 13: transaksi dengan kunci tipe, siapa tiba lebih dulu dapat jatah).
 * Berjalan di MySQL khusus test, tanpa RefreshDatabase, karena transaksi pembungkus akan menyembunyikan
 * data dari proses lain. Proses PHP terpisah dipakai (bukan `php -S`, yang tidak punya banyak worker di Windows).
 */
class PemesananKonkurensiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // migrate:fresh menghapus semua tabel: jangan pernah jalan di luar database test
        $db = config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'mysql' || ! str_ends_with((string) $db, '_test')) {
            $this->fail("Tes ini hanya boleh jalan di MySQL khusus test (nama berakhiran _test), bukan '{$db}'.");
        }

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        // Data tes ini di-commit (perlu, agar terlihat oleh proses lain); kosongkan agar tes RefreshDatabase
        // sesudahnya tidak ikut melihatnya. Pengaman nama database di setUp sudah lolos bila sampai sini.
        if ($this->app && str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            Artisan::call('migrate:fresh', ['--force' => true]);
        }

        parent::tearDown();
    }

    /** Skenario (a): 1 unit, 2 permintaan bersamaan => satu 201 dan satu 409, tanpa tagihan untuk yang kalah. */
    public function test_dua_permintaan_untuk_unit_terakhir_hanya_satu_yang_berhasil(): void
    {
        $tipe = $this->buatTipe(unit: 1);

        $hasil = $this->jalankanBersamaan([$this->spekPesan($tipe, 'p0'), $this->spekPesan($tipe, 'p1')]);

        $this->assertSame([201, 409], collect($hasil)->pluck('status')->sort()->values()->all(), json_encode($hasil));
        $ditolak = collect($hasil)->firstWhere('status', 409);
        $this->assertSame('UNIT_TIDAK_TERSEDIA', $ditolak['body']['code'] ?? null);

        $this->assertSame(1, Pemesanan::where('id_tipe', $tipe->id_tipe)->count(), 'Unit terakhir terpesan ganda');
        $this->assertSame(1, Pembayaran::count(), 'Pemesan yang kalah tidak boleh mendapat tagihan');
    }

    /** Skenario (b): 2 unit, 2 permintaan bersamaan => keduanya 201 (kunci tidak boleh menolak yang masih muat). */
    public function test_dua_permintaan_untuk_dua_unit_keduanya_berhasil(): void
    {
        $tipe = $this->buatTipe(unit: 2);

        $hasil = $this->jalankanBersamaan([$this->spekPesan($tipe, 'p0'), $this->spekPesan($tipe, 'p1')]);

        $this->assertSame([201, 201], collect($hasil)->pluck('status')->values()->all(), json_encode($hasil));
        $this->assertSame(2, Pemesanan::where('id_tipe', $tipe->id_tipe)->count());
        $this->assertSame(2, Pembayaran::count());
        $this->assertCount(2, collect($hasil)->pluck('body.data.midtrans.order_id')->unique());
    }

    /**
     * Skenario (c): pembayaran terlambat (pesanan A sudah dibatalkan karena kedaluwarsa) berebut unit terakhir
     * dengan pesanan baru B. Tepat satu yang memegang unit: A dihidupkan dan B ditolak, atau B menang dan A
     * direfund. Webhook memeriksa ulang sisa unit di dalam transaksi dengan kunci tipe yang sama.
     */
    public function test_pembayaran_terlambat_dan_pesanan_baru_tidak_memegang_unit_yang_sama(): void
    {
        $tipe = $this->buatTipe(unit: 1);
        $a = $this->buatPesananKedaluwarsa($tipe, 'a');

        $hasil = $this->jalankanBersamaan([$this->spekPesan($tipe, 'b'), $this->spekWebhook($a['order_id'], 600000)]);

        $this->assertSame(200, $hasil[1]['status'], json_encode($hasil));
        $memegang = Pemesanan::where('id_tipe', $tipe->id_tipe)->whereIn('status', ['dikonfirmasi', 'menunggu_pembayaran'])->count();
        $this->assertSame(1, $memegang, 'Unit terakhir dipegang dua pesanan: '.json_encode($hasil));

        if ($hasil[0]['status'] === 201) {
            // B menang: A tetap batal dan dananya dikembalikan (QRIS lewat Midtrans, gateway palsu di proses lain)
            $this->assertSame('dibatalkan', $a['pemesanan']->fresh()->status);
            $this->assertNotNull($a['pembayaran']->fresh()->refund_cara);
        } else {
            $this->assertSame(409, $hasil[0]['status']);
            $this->assertSame('dikonfirmasi', $a['pemesanan']->fresh()->status);
            $this->assertSame('lunas', $a['pembayaran']->fresh()->status);
        }
    }

    /** Skenario (d): notifikasi settlement yang sama datang dua kali bersamaan => hanya diproses sekali. */
    public function test_notifikasi_settlement_ganda_bersamaan_idempoten(): void
    {
        $tipe = $this->buatTipe(unit: 1);
        $a = $this->buatPesananMenunggu($tipe, 'a');

        $hasil = $this->jalankanBersamaan([
            $this->spekWebhook($a['order_id'], 600000), $this->spekWebhook($a['order_id'], 600000),
        ]);

        $this->assertSame([200, 200], [$hasil[0]['status'], $hasil[1]['status']], json_encode($hasil));
        $this->assertSame('dikonfirmasi', $a['pemesanan']->fresh()->status);
        $this->assertSame('lunas', $a['pembayaran']->fresh()->status);
        $this->assertSame(1, LogAktivitas::where('aksi', 'like', 'Pembayaran berhasil%')->count(), 'Diproses lebih dari sekali');
    }

    /**
     * Skenario (e): pelanggan membatalkan tepat saat notifikasi settlement tiba. Hasilnya harus konsisten dan tidak
     * boleh keduanya: batal menang (pesanan dibatalkan DAN pembayaran direfund) atau settlement menang (pesanan
     * dikonfirmasi DAN pembatalan ditolak 409). Keduanya memakai kunci baris pesanan yang sama.
     */
    public function test_pembatalan_dan_settlement_bersamaan_hasilnya_konsisten(): void
    {
        $tipe = $this->buatTipe(unit: 1);
        $a = $this->buatPesananMenunggu($tipe, 'a');

        $hasil = $this->jalankanBersamaan([
            ['method' => 'POST', 'uri' => '/api/v1/pemesanan/'.$a['pemesanan']->id_pemesanan.'/batalkan', 'token' => $a['token'], 'body' => []],
            $this->spekWebhook($a['order_id'], 600000),
        ]);

        $this->assertSame(200, $hasil[1]['status'], 'Webhook selalu 200: '.json_encode($hasil));
        $p = $a['pemesanan']->fresh();
        $bayar = $a['pembayaran']->fresh();

        if ($hasil[0]['status'] === 200) {
            // Pembatalan menang: pesanan batal, uang yang masuk kemudian dikembalikan (QRIS lewat gateway palsu di proses pekerja)
            $this->assertSame(['dibatalkan', 'dibatalkan_pelanggan'], [$p->status, $p->alasan_pembatalan], json_encode($hasil));
            $this->assertSame(['dikembalikan', 'midtrans', true], [$bayar->status, $bayar->refund_cara, $bayar->refund_selesai], json_encode($hasil));
        } else {
            // Settlement menang: pesanan sudah dikonfirmasi, pembatalan ditolak
            $this->assertSame(409, $hasil[0]['status'], json_encode($hasil));
            $this->assertSame('STATUS_TIDAK_VALID', $hasil[0]['body']['code'] ?? null);
            $this->assertSame('dikonfirmasi', $p->status, json_encode($hasil));
            $this->assertSame(['lunas', null], [$bayar->status, $bayar->refund_cara], json_encode($hasil));
        }
    }

    /**
     * Skenario (f): dua admin menyerahkan unit pada dua pesanan berbeda dan sama-sama memilih plat yang sama.
     * Unit dikunci (lockForUpdate) lalu diperiksa ulang: satu 200, satu 409 UNIT_SUDAH_DIPAKAI; unit hanya dipegang
     * satu pesanan berjalan, dan jaminan hanya tercatat untuk pemenang.
     */
    public function test_dua_admin_memilih_plat_yang_sama_hanya_satu_yang_berhasil(): void
    {
        $tipe = $this->buatTipe(unit: 2);
        $unit0 = $tipe->kendaraan()->orderBy('id_kendaraan')->first();
        $a = $this->buatPesananDikonfirmasi($tipe, 'a');
        $b = $this->buatPesananDikonfirmasi($tipe, 'b');

        $hasil = $this->jalankanBersamaan([
            $this->spekSerahTerima($a['pemesanan']->id_pemesanan, $unit0->id_kendaraan, $this->buatAdminToken('admin1')),
            $this->spekSerahTerima($b['pemesanan']->id_pemesanan, $unit0->id_kendaraan, $this->buatAdminToken('admin2')),
        ]);

        $this->assertSame([200, 409], collect($hasil)->pluck('status')->sort()->values()->all(), json_encode($hasil));
        $this->assertSame('UNIT_SUDAH_DIPAKAI', collect($hasil)->firstWhere('status', 409)['body']['code'] ?? null);

        $this->assertSame(1, Pemesanan::where('id_kendaraan', $unit0->id_kendaraan)->where('status', 'berjalan')->count(), 'Plat yang sama dipegang dua pesanan');
        $this->assertSame(1, SerahTerima::count());
        $this->assertSame(1, Pemesanan::where('status', 'dikonfirmasi')->count(), 'Pesanan yang kalah tetap dikonfirmasi');
        $pemenang = Pemesanan::where('status', 'berjalan')->sole();
        $this->assertSame(4, Jaminan::where('id_pemesanan', $pemenang->id_pemesanan)->count());
        $this->assertSame(4, Jaminan::count(), 'Pemesan yang kalah tidak boleh meninggalkan jaminan');
    }

    /** Skenario (g): dua admin menyerahkan pesanan yang sama (unit berbeda): satu 200, satu 409 STATUS_TIDAK_VALID. */
    public function test_dua_admin_menyerahkan_pesanan_yang_sama_hanya_satu_yang_berhasil(): void
    {
        $tipe = $this->buatTipe(unit: 2);
        $unit = $tipe->kendaraan()->orderBy('id_kendaraan')->get();
        $a = $this->buatPesananDikonfirmasi($tipe, 'a');

        $hasil = $this->jalankanBersamaan([
            $this->spekSerahTerima($a['pemesanan']->id_pemesanan, $unit[0]->id_kendaraan, $this->buatAdminToken('admin1')),
            $this->spekSerahTerima($a['pemesanan']->id_pemesanan, $unit[1]->id_kendaraan, $this->buatAdminToken('admin2')),
        ]);

        $this->assertSame([200, 409], collect($hasil)->pluck('status')->sort()->values()->all(), json_encode($hasil));
        $this->assertSame('STATUS_TIDAK_VALID', collect($hasil)->firstWhere('status', 409)['body']['code'] ?? null);
        $this->assertSame(1, SerahTerima::where('id_pemesanan', $a['pemesanan']->id_pemesanan)->count());
        $this->assertSame(4, Jaminan::count());
    }

    // ---- bantu ----

    private function buatTipe(int $unit): TipeKendaraan
    {
        $tipe = TipeKendaraan::create(['nama' => 'Terakhir', 'merk' => 'M', 'kursi' => 5, 'bahan_bakar' => 'bensin',
            'harga_sopir_per_hari' => 600000, 'harga_lepas_kunci_manual' => 300000, 'harga_lepas_kunci_matic' => 350000]);
        for ($i = 0; $i < $unit; $i++) {
            Kendaraan::create(['id_tipe' => $tipe->id_tipe, 'plat_nomor' => "N {$i} AB", 'tahun' => 2022,
                'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
        }

        return $tipe;
    }

    private function buatPelanggan(string $kode): array
    {
        $akun = Akun::create(['email' => "{$kode}@mail.test", 'password' => 'abc12345', 'email_terverifikasi_at' => now()]);
        $p = Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => strtoupper($kode), 'no_hp' => '0812345678',
            'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang']);

        return [$p, $akun->createToken('user', ['user'], now()->addHour())->plainTextToken];
    }

    private function buatPesananKedaluwarsa(TipeKendaraan $tipe, string $kode): array
    {
        $a = $this->buatPesananMenunggu($tipe, $kode);
        $a['pemesanan']->update(['status' => 'dibatalkan', 'alasan_pembatalan' => 'kedaluwarsa', 'batas_pembayaran' => now()->subMinutes(5)]);
        $a['pembayaran']->update(['status' => 'gagal']);

        return $a;
    }

    private function buatPesananMenunggu(TipeKendaraan $tipe, string $kode): array
    {
        [$p, $token] = $this->buatPelanggan($kode);
        $mulai = now()->addDays(3);
        $pemesanan = Pemesanan::create([
            'id_pelanggan' => $p->id_pelanggan, 'id_tipe' => $tipe->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual',
            'tgl_pesan' => now()->toDateString(), 'tgl_mulai' => $mulai->toDateString(), 'durasi' => 2,
            'tgl_kembali' => $mulai->copy()->addDay()->toDateString(), 'metode_ambil' => 'ambil_di_kantor', 'jam_serah' => '09:00',
            'domisili' => 'malang_raya', 'setuju_syarat' => true, 'total_biaya' => 600000,
            'status' => 'menunggu_pembayaran', 'batas_pembayaran' => now()->addMinutes(30),
        ]);
        $pembayaran = Pembayaran::create(['id_pemesanan' => $pemesanan->id_pemesanan, 'jenis' => 'sewa', 'jumlah' => 600000,
            'order_id_midtrans' => $pemesanan->nomor.'-1', 'status' => 'menunggu']);

        return ['pemesanan' => $pemesanan, 'pembayaran' => $pembayaran, 'order_id' => $pembayaran->order_id_midtrans, 'token' => $token];
    }

    private function buatPesananDikonfirmasi(TipeKendaraan $tipe, string $kode): array
    {
        $a = $this->buatPesananMenunggu($tipe, $kode);
        $a['pemesanan']->update(['status' => 'dikonfirmasi']);
        $a['pembayaran']->update(['status' => 'lunas', 'metode' => 'qris', 'tgl_bayar' => now()]);

        return $a;
    }

    private function buatAdminToken(string $kode): string
    {
        $akun = Akun::create(['nama' => ucfirst($kode), 'email' => "{$kode}@mail.test", 'password' => 'abc12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);

        return $akun->createToken('admin', ['admin'], now()->addHour())->plainTextToken;
    }

    private function spekSerahTerima(int $idPemesanan, int $idUnit, string $token): array
    {
        // Foto jaminan wajib: PNG 1x1 nyata yang dibaca pekerja di proses lain
        $png = tempnam(sys_get_temp_dir(), 'jmn');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        return ['method' => 'POST', 'uri' => "/api/v1/admin/pemesanan/{$idPemesanan}/serah-terima", 'token' => $token, 'body' => [
            'id_kendaraan' => $idUnit, 'no_identitas' => '3573010000000009', 'odometer_awal' => 45800, 'bbm_awal' => 'penuh',
            'jaminan' => ['dokumen_tambahan' => 'kk'],
        ], 'files' => ['foto_jaminan' => [
            'ktp_asli' => $png, 'sim_c_asli' => $png, 'dokumen_tambahan' => $png, 'motor_stnk' => $png,
        ]]];
    }

    private function spekPesan(TipeKendaraan $tipe, string $kode): array
    {
        [, $token] = $this->buatPelanggan($kode);

        return ['method' => 'POST', 'uri' => '/api/v1/pemesanan', 'token' => $token, 'body' => [
            'id_tipe' => $tipe->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'manual',
            'tgl_mulai' => now()->addDays(3)->toDateString(), 'durasi' => 2, 'jam_serah' => '09:00',
            'metode_ambil' => 'ambil_di_kantor', 'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ]];
    }

    private function spekWebhook(string $orderId, int $jumlah): array
    {
        $gross = number_format($jumlah, 2, '.', '');

        return ['method' => 'POST', 'uri' => '/api/v1/webhook/midtrans', 'token' => null, 'body' => [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross,
            'transaction_status' => 'settlement', 'payment_type' => 'qris',
            'signature_key' => hash('sha512', $orderId.'200'.$gross.config('midtrans.server_key')),
        ]];
    }

    /**
     * @param  list<array>  $spek
     * @return list<array{status:int, body:array}>
     */
    private function jalankanBersamaan(array $spek): array
    {
        // Waktu mulai cukup jauh agar semua proses selesai boot lebih dulu
        $mulai = microtime(true) + 4 + count($spek);
        $proses = $berkas = [];
        $akarDisk = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rental-uji-'.bin2hex(random_bytes(6));
        foreach ($spek as $s) {
            $berkas[] = $f = tempnam(sys_get_temp_dir(), 'req');
            file_put_contents($f, json_encode($s));
            $proses[] = $this->jalankanPekerja($f, $mulai, $akarDisk);
        }

        $hasil = array_map(fn ($p) => $this->tunggu($p), $proses);
        array_map('unlink', $berkas);
        \Illuminate\Support\Facades\File::deleteDirectory($akarDisk);

        return $hasil;
    }

    /** @return array{0: resource, 1: array} */
    private function jalankanPekerja(string $berkas, float $mulai, string $akarDisk): array
    {
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DISK_LOCAL_ROOT' => $akarDisk,
            'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => config('database.connections.mysql.database'),
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'PEMBAYARAN_GATEWAY' => 'palsu',
            'MIDTRANS_SERVER_KEY' => (string) config('midtrans.server_key'),
        ]);
        $perintah = [PHP_BINARY, base_path('tests/Support/kirim_request.php'), $berkas, sprintf('%.6F', $mulai)];
        $proc = proc_open($perintah, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipa, base_path(), $env);
        $this->assertIsResource($proc, 'Gagal menjalankan proses pekerja');

        return [$proc, $pipa];
    }

    private function tunggu(array $p): array
    {
        [$proc, $pipa] = $p;
        $keluar = stream_get_contents($pipa[1]);
        $galat = stream_get_contents($pipa[2]);
        proc_close($proc);

        $baris = array_values(array_filter(array_map('trim', explode("\n", $keluar))));
        $json = json_decode((string) end($baris), true);
        $this->assertIsArray($json, "Pekerja tidak mengembalikan JSON.\nstdout: {$keluar}\nstderr: {$galat}");

        return $json;
    }
}
