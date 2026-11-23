<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Kendaraan;
use App\Models\Pelanggan;
use App\Models\Pembayaran;
use App\Models\Pemesanan;
use App\Models\Pengaturan;
use App\Models\TipeKendaraan;
use App\Services\Pembayaran\PembayaranGateway;
use App\Services\Pembayaran\PembayaranGatewayPalsu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** POST /pemesanan (Spek API bagian 6): urutan pemeriksaan, hitungan biaya, dan atomisitas. */
class PemesananTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/pemesanan';

    private Pelanggan $pelanggan;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->pelanggan, $this->token] = $this->buatPelanggan('budi@mail.test', 'Kota Malang');
    }

    private function buatPelanggan(string $email, string $kota): array
    {
        $akun = Akun::create(['email' => $email, 'password' => 'abc12345', 'email_terverifikasi_at' => now()]);
        $p = Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'Budi', 'no_hp' => '0812345678',
            'provinsi' => 'Jawa Timur', 'kota' => $kota]);

        return [$p, $akun->createToken('user', ['user'])->plainTextToken];
    }

    private function tipe(int $unit = 1): TipeKendaraan
    {
        // nama + merk unik: pemanggilan berulang diberi nomor urut (Brio, Brio 2, ...)
        $nama = 'Brio';
        for ($n = 2; TipeKendaraan::where('nama', $nama)->where('merk', 'Honda')->exists(); $n++) {
            $nama = "Brio {$n}";
        }
        $t = TipeKendaraan::create(['nama' => $nama, 'merk' => 'Honda', 'kursi' => 5, 'bahan_bakar' => 'bensin',
            'harga_sopir_per_hari' => 650000, 'harga_lepas_kunci_manual' => 300000, 'harga_lepas_kunci_matic' => 350000]);
        for ($i = 0; $i < $unit; $i++) {
            Kendaraan::create(['id_tipe' => $t->id_tipe, 'plat_nomor' => "N {$t->id_tipe}{$i} AB", 'tahun' => 2022,
                'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
        }

        return $t;
    }

    private function kunci(TipeKendaraan $t, array $ubah = []): array
    {
        return $ubah + [
            'id_tipe' => $t->id_tipe, 'jenis_sewa' => 'lepas_kunci', 'transmisi' => 'matic',
            'tgl_mulai' => now()->addDays(5)->toDateString(), 'durasi' => 3, 'jam_serah' => '09:00',
            'metode_ambil' => 'diantar', 'lokasi_jemput' => 'Jl. Veteran No. 5, Malang',
            'setuju_syarat' => true, 'setuju_syarat_jaminan' => true,
        ];
    }

    private function sopir(TipeKendaraan $t, array $ubah = []): array
    {
        return $ubah + [
            'id_tipe' => $t->id_tipe, 'jenis_sewa' => 'dengan_sopir',
            'tgl_mulai' => now()->addDays(5)->toDateString(), 'durasi' => 2, 'jam_serah' => '05:30',
            'lokasi_jemput' => 'Stasiun Malang', 'tujuan' => 'Malang, Batu', 'setuju_syarat' => true,
        ];
    }

    private function pesan(array $isi, ?string $token = null)
    {
        $this->app['auth']->forgetGuards(); // guard menyimpan user dari permintaan sebelumnya dalam satu tes

        return $this->withToken($token ?? $this->token)->postJson(self::URL, $isi);
    }

    public function test_lepas_kunci_berhasil_menghitung_biaya_dan_menerbitkan_tagihan(): void
    {
        $t = $this->tipe();
        $mulai = now()->addDays(5)->toDateString();

        $r = $this->pesan($this->kunci($t))->assertCreated()
            ->assertJsonPath('data.status', 'menunggu_pembayaran')
            ->assertJsonPath('data.total_biaya', 350000 * 3)
            ->assertJsonPath('data.tgl_kembali', now()->addDays(7)->toDateString())
            ->assertJsonStructure(['data' => ['id', 'nomor', 'batas_pembayaran', 'midtrans' => ['snap_token', 'order_id']]]);

        $id = $r->json('data.id');
        $this->assertSame(sprintf('PSN-%04d', $id), $r->json('data.nomor'));
        $this->assertSame(sprintf('PSN-%04d-1', $id), $r->json('data.midtrans.order_id'));
        $this->assertStringEndsWith('+07:00', $r->json('data.batas_pembayaran'));

        $p = Pemesanan::findOrFail($id);
        $this->assertSame('malang_raya', $p->domisili);
        $this->assertSame($mulai, $p->tgl_mulai->toDateString());
        $this->assertEqualsWithDelta(60 * 60, now()->diffInSeconds($p->batas_pembayaran, false), 5);

        $bayar = Pembayaran::where('id_pemesanan', $id)->sole();
        $this->assertSame([1050000, 'menunggu', 'sewa'], [$bayar->jumlah, $bayar->status, $bayar->jenis]);
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'Pesanan dibuat', 'objek' => 'pemesanan', 'id_objek' => $id, 'pelaku' => 'pelanggan']);
    }

    public function test_dengan_sopir_memakai_tarif_sopir_dan_domisili_luar_malang_raya(): void
    {
        [, $tokenLuar] = $this->buatPelanggan('jauh@mail.test', 'Kota Surabaya');
        $t = $this->tipe();

        $r = $this->pesan($this->sopir($t), $tokenLuar)->assertCreated()->assertJsonPath('data.total_biaya', 650000 * 2);

        $p = Pemesanan::findOrFail($r->json('data.id'));
        $this->assertSame('luar_malang_raya', $p->domisili);
        $this->assertNull($p->metode_ambil);
        $this->assertNull($p->transmisi);
    }

    public function test_validasi_dan_hak_akses(): void
    {
        $t = $this->tipe();

        $this->postJson(self::URL, $this->kunci($t))->assertUnauthorized()->assertJsonPath('code', 'BELUM_LOGIN');

        $admin = Akun::create(['nama' => 'Adm', 'email' => 'adm@mail.test', 'password' => 'abc12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);
        $this->pesan($this->kunci($t), $admin->createToken('admin', ['admin'])->plainTextToken)
            ->assertForbidden()->assertJsonPath('code', 'AKSES_DITOLAK');

        $this->pesan(['id_tipe' => 9999])->assertStatus(422)->assertJsonPath('code', 'VALIDASI_GAGAL')
            ->assertJsonValidationErrors(['id_tipe', 'jenis_sewa', 'tgl_mulai', 'durasi', 'jam_serah', 'setuju_syarat']);
        $this->pesan($this->kunci($t, ['jam_serah' => '22:00']))->assertStatus(422)->assertJsonValidationErrors('jam_serah');
        $this->pesan($this->kunci($t, ['jam_serah' => '06:59']))->assertStatus(422)->assertJsonValidationErrors('jam_serah');
        $this->pesan($this->kunci($t, ['tgl_mulai' => now()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('tgl_mulai');
        $this->pesan($this->kunci($t, ['durasi' => 31]))->assertStatus(422)->assertJsonValidationErrors('durasi');
        $this->pesan($this->kunci($t, ['transmisi' => null]))->assertStatus(422)->assertJsonValidationErrors('transmisi');
        $this->pesan($this->kunci($t, ['setuju_syarat_jaminan' => false]))->assertStatus(422)->assertJsonValidationErrors('setuju_syarat_jaminan');
        $this->pesan($this->kunci($t, ['metode_ambil' => 'diantar', 'lokasi_jemput' => null]))->assertStatus(422)->assertJsonValidationErrors('lokasi_jemput');
        $this->pesan($this->sopir($t, ['tujuan' => null]))->assertStatus(422)->assertJsonValidationErrors('tujuan');
        // Sopir boleh jam bebas (05:30 sudah dipakai di helper) dan tidak butuh jaminan
        $this->pesan($this->sopir($t))->assertCreated();
        $this->assertSame(1, Pemesanan::count(), 'Validasi gagal tidak boleh meninggalkan pesanan');
    }

    public function test_tipe_tanpa_tarif_untuk_pilihan_itu_ditolak_422(): void
    {
        $t = $this->tipe();
        $t->update(['harga_lepas_kunci_matic' => null]);

        $this->pesan($this->kunci($t, ['transmisi' => 'matic']))->assertStatus(422)->assertJsonValidationErrors('transmisi');
        $this->assertSame(0, Pemesanan::count());
    }

    public function test_urutan_galat_tagihan_lalu_pesanan_menunggu_lalu_unit(): void
    {
        $t = $this->tipe(1);
        $lain = $this->tipe(1);
        $mulai = now()->addDays(5)->toDateString();

        // Pesanan menunggu pembayaran milik user + unit tipe $t habis
        $this->pesan($this->kunci($t))->assertCreated();
        $this->pesan($this->kunci($lain))->assertStatus(409)->assertJsonPath('code', 'PESANAN_MENUNGGU_ADA');

        // Tagihan tambahan yang belum lunas didahulukan atas PESANAN_MENUNGGU_ADA
        $lama = Pemesanan::first();
        Pembayaran::create(['id_pemesanan' => $lama->id_pemesanan, 'jenis' => 'tambahan', 'jumlah' => 100000, 'status' => 'menunggu']);
        $this->pesan($this->kunci($lain))->assertStatus(409)->assertJsonPath('code', 'TAGIHAN_BELUM_LUNAS');

        // Pelanggan lain pada tipe/tanggal yang sama: unit habis
        [, $tokenB] = $this->buatPelanggan('b@mail.test', 'Kota Batu');
        $this->pesan($this->kunci($t), $tokenB)->assertStatus(409)
            ->assertJsonPath('code', 'UNIT_TIDAK_TERSEDIA')->assertJsonPath('message', 'Mobil sudah dipesan pada tanggal tersebut');
        $this->assertSame(1, Pemesanan::count());
        $this->assertSame(2, Pembayaran::count(), 'Yang ditolak tidak boleh mendapat tagihan');
    }

    public function test_pesanan_menunggu_yang_lewat_batas_tidak_menahan_unit_maupun_pemesan(): void
    {
        $t = $this->tipe(1);
        $this->pesan($this->kunci($t))->assertCreated();
        Pemesanan::query()->update(['batas_pembayaran' => now()->subMinute()]);

        [, $tokenB] = $this->buatPelanggan('b@mail.test', 'Kota Malang');
        $this->pesan($this->kunci($t), $tokenB)->assertCreated();
        // Pemesan pertama pun boleh memesan lagi: pesanan lamanya sudah lewat batas
        $this->pesan($this->kunci($this->tipe(1)))->assertCreated();
    }

    public function test_rentang_yang_tidak_beririsan_tidak_bentrok(): void
    {
        $t = $this->tipe(1);
        $this->pesan($this->kunci($t, ['durasi' => 2]))->assertCreated();   // hari ke-5 dan ke-6
        Pemesanan::query()->update(['status' => 'dikonfirmasi']);

        $this->pesan($this->kunci($t, ['tgl_mulai' => now()->addDays(6)->toDateString(), 'durasi' => 1]))
            ->assertStatus(409)->assertJsonPath('code', 'UNIT_TIDAK_TERSEDIA');
        $this->pesan($this->kunci($t, ['tgl_mulai' => now()->addDays(7)->toDateString(), 'durasi' => 1]))->assertCreated();
    }

    public function test_batas_jumlah_sopir_dihitung_lintas_tipe(): void
    {
        Pengaturan::updateOrCreate(['kunci' => 'jumlah_sopir'], ['nilai' => '1']);
        $a = $this->tipe(2);
        $b = $this->tipe(2);

        $this->pesan($this->sopir($a))->assertCreated();
        Pemesanan::query()->update(['status' => 'dikonfirmasi']); // lepas dari aturan satu pesanan menunggu

        $this->pesan($this->sopir($b))->assertStatus(409)->assertJsonPath('code', 'SOPIR_TIDAK_TERSEDIA');
        // Lepas kunci tidak memakai sopir
        $this->pesan($this->kunci($b))->assertCreated();

        // Pengaturan kosong = tidak dibatasi
        Pengaturan::where('kunci', 'jumlah_sopir')->update(['nilai' => null]);
        Pemesanan::query()->update(['status' => 'dikonfirmasi']);
        $this->pesan($this->sopir($b))->assertCreated();
    }

    public function test_gateway_gagal_membatalkan_pesanan_dan_melepas_unit(): void
    {
        $t = $this->tipe(1);
        $gateway = app(PembayaranGateway::class);
        $gateway->gagalBuat = true;

        $this->pesan($this->kunci($t))->assertStatus(502)->assertJsonPath('code', 'PEMBAYARAN_GAGAL');

        // Pesanan sempat tersimpan (transaksi sudah commit sebelum gateway dipanggil), lalu dibatalkan otomatis
        $p = Pemesanan::sole();
        $this->assertSame(['dibatalkan', 'pembayaran_gagal'], [$p->status, $p->alasan_pembatalan]);
        $this->assertSame('gagal', Pembayaran::sole()->status);
        $this->assertDatabaseHas('log_aktivitas', ['id_objek' => $p->id_pemesanan, 'pelaku' => 'sistem',
            'aksi' => 'Pesanan dibatalkan: tagihan pembayaran gagal dibuat']);

        // Unit lepas dan pemesan tidak terhalang PESANAN_MENUNGGU_ADA: boleh mencoba lagi pada tanggal yang sama
        $gateway->gagalBuat = false;
        $this->pesan($this->kunci($t))->assertCreated();
    }

    public function test_token_berlaku_sampai_batas_pembayaran(): void
    {
        $t = $this->tipe();
        $this->pesan($this->kunci($t))->assertCreated();

        $this->assertSame(60, app(PembayaranGateway::class)->transaksiDibuat[0]['kedaluwarsaMenit']);
    }

    public function test_testing_memakai_gateway_palsu(): void
    {
        $this->assertInstanceOf(PembayaranGatewayPalsu::class, app(PembayaranGateway::class));
    }
}
