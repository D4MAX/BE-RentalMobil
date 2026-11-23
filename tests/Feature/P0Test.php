<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Kendaraan;
use App\Models\Pemesanan;
use App\Models\Pelanggan;
use App\Models\TipeKendaraan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class P0Test extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    private function dataDaftar(array $ubah = []): array
    {
        return $ubah + [
            'nama' => 'Budi', 'email' => 'budi@mail.test', 'no_hp' => '081234567890',
            'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ];
    }

    /** POST /auth/register sebagai form multipart/form-data (bukan JSON). */
    private function register(array $isi)
    {
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data']);

        return $this->call('POST', self::API.'/auth/register', $isi, [], [], $server);
    }

    private function daftarDanAmbilToken(array $ubah = []): string
    {
        $token = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($isi) use (&$token) {
            preg_match('/token=(\w+)/', $isi, $m);
            $token = $m[1];
        });
        $this->register($this->dataDaftar($ubah))->assertCreated();

        return $token;
    }

    public function test_daftar_verifikasi_login_berhasil(): void
    {
        $token = $this->daftarDanAmbilToken();

        $this->postJson(self::API.'/auth/verifikasi-email', ['token' => $token])->assertOk();

        $r = $this->postJson(self::API.'/auth/login', ['email' => 'budi@mail.test', 'password' => 'rahasia123'])
            ->assertOk()->assertJsonPath('data.user.role', 'user');

        $this->withToken($r->json('data.token'))->getJson(self::API.'/me')
            ->assertOk()->assertJsonPath('data.kota', 'Kota Malang');
    }

    public function test_register_tidak_mengembalikan_token(): void
    {
        $this->daftarDanAmbilToken();
    }

    public function test_login_sebelum_verifikasi_ditolak(): void
    {
        $this->daftarDanAmbilToken();

        $this->postJson(self::API.'/auth/login', ['email' => 'budi@mail.test', 'password' => 'rahasia123'])
            ->assertForbidden()->assertJsonPath('code', 'EMAIL_BELUM_TERVERIFIKASI');
    }

    public function test_token_verifikasi_kedaluwarsa_atau_dipakai_ditolak(): void
    {
        $token = $this->daftarDanAmbilToken();

        $this->travel(13)->hours();
        $this->postJson(self::API.'/auth/verifikasi-email', ['token' => $token])
            ->assertStatus(422)->assertJsonPath('code', 'TOKEN_TIDAK_VALID');
        $this->travelBack();

        $this->postJson(self::API.'/auth/verifikasi-email', ['token' => $token])->assertOk();
        $this->postJson(self::API.'/auth/verifikasi-email', ['token' => $token])->assertStatus(422);
    }

    public function test_register_form_multipart_diterima_dan_field_wajib_divalidasi(): void
    {
        $this->register([])->assertStatus(422)->assertJsonValidationErrors(['nama', 'email', 'no_hp', 'provinsi', 'kota', 'password']);
        $this->register($this->dataDaftar(['password_confirmation' => 'beda12345']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->register($this->dataDaftar(['no_hp' => '123']))->assertStatus(422)->assertJsonValidationErrors('no_hp');
        Mail::fake();
        $this->register($this->dataDaftar())->assertCreated()->assertJsonPath('data.email', 'budi@mail.test');
        $this->assertSame('081234567890', Pelanggan::first()->no_hp);
    }

    public function test_password_tanpa_angka_ditolak_dan_email_ganda_ditolak(): void
    {
        $this->register($this->dataDaftar(['password' => 'tanpaangka', 'password_confirmation' => 'tanpaangka']))
            ->assertStatus(422)->assertJsonStructure(['message', 'code', 'errors' => ['password']]);

        Akun::create(['email' => 'budi@mail.test', 'password' => 'x12345678', 'email_terverifikasi_at' => now()]);
        $this->register($this->dataDaftar())
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_daftar_ulang_akun_belum_terverifikasi_menggantikan_data_lama_setelah_jendela_lewat(): void
    {
        Mail::fake();
        $this->assertSame(60, config('rental.daftar_ulang_tunggu_menit'), 'Jendela bawaan 1 jam');
        $this->register($this->dataDaftar())->assertCreated();

        $this->travel(config('rental.daftar_ulang_tunggu_menit') + 1)->minutes();
        $this->register($this->dataDaftar(['nama' => 'Budi Baru']))->assertCreated();

        $this->assertSame(1, Akun::count());
        $this->assertSame('Budi Baru', Pelanggan::first()->nama);
    }

    public function test_daftar_ulang_di_dalam_jendela_ditolak_agar_akun_baru_tidak_bisa_ditimpa_orang_lain(): void
    {
        Mail::fake();
        $this->register($this->dataDaftar(['nama' => 'Budi Asli', 'password' => 'asli12345', 'password_confirmation' => 'asli12345']))->assertCreated();

        // Orang lain memakai email yang sama dengan kata sandinya sendiri, sebelum jendela habis
        $this->travel(config('rental.daftar_ulang_tunggu_menit') - 1)->minutes();
        $this->register($this->dataDaftar(['nama' => 'Penyerang', 'password' => 'serang12345', 'password_confirmation' => 'serang12345']))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('Budi Asli', Pelanggan::first()->nama, 'Data pendaftar pertama tidak boleh tertimpa');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('asli12345', Akun::first()->password));
    }

    public function test_akun_belum_terverifikasi_lebih_dari_30_hari_tidak_bisa_ditimpa_oleh_pendaftaran(): void
    {
        // Pembersihan harian yang menghapusnya (akun:bersihkan-belum-verifikasi); register sendiri tidak menimpa yang sudah tua
        Mail::fake();
        $this->register($this->dataDaftar())->assertCreated();
        $this->travel(31)->days();

        $this->register($this->dataDaftar(['nama' => 'Baru']))->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_salah_dan_dibatasi_5_per_menit(): void
    {
        Akun::create(['email' => 'a@mail.test', 'password' => 'benar12345', 'email_terverifikasi_at' => now()]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::API.'/auth/login', ['email' => 'a@mail.test', 'password' => 'salah'])
                ->assertUnauthorized()->assertJsonPath('code', 'KREDENSIAL_SALAH');
        }
        $this->postJson(self::API.'/auth/login', ['email' => 'a@mail.test', 'password' => 'salah'])
            ->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
    }

    public function test_admin_nonaktif_ditolak_dan_admin_sementara_wajib_ganti_password(): void
    {
        Akun::create(['nama' => 'Adm', 'email' => 'x@mail.test', 'password' => 'abc12345', 'role' => 'admin',
            'aktif' => false, 'email_terverifikasi_at' => now()]);
        $this->postJson(self::API.'/auth/login', ['email' => 'x@mail.test', 'password' => 'abc12345'])
            ->assertForbidden()->assertJsonPath('code', 'AKUN_NONAKTIF');

        Akun::create(['nama' => 'Adm2', 'email' => 'y@mail.test', 'password' => 'sementara1', 'role' => 'admin',
            'wajib_ganti_password' => true, 'email_terverifikasi_at' => now()]);
        $r = $this->postJson(self::API.'/auth/login', ['email' => 'y@mail.test', 'password' => 'sementara1'])
            ->assertOk()->assertJsonPath('data.wajib_ganti_password', true);
        $token = $r->json('data.token');

        $this->withToken($token)->getJson(self::API.'/me')
            ->assertForbidden()->assertJsonPath('code', 'GANTI_PASSWORD_DULU');

        $this->withToken($token)->putJson(self::API.'/profil/password', [
            'password_lama' => 'sementara1', 'password_baru' => 'baru12345', 'konfirmasi' => 'baru12345',
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(self::API.'/me')->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_logout_mencabut_token(): void
    {
        Akun::create(['email' => 'a@mail.test', 'password' => 'benar12345', 'email_terverifikasi_at' => now()]);
        $token = $this->postJson(self::API.'/auth/login', ['email' => 'a@mail.test', 'password' => 'benar12345'])->json('data.token');

        $this->withToken($token)->postJson(self::API.'/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson(self::API.'/me')->assertUnauthorized();
    }

    public function test_katalog_tidak_menampilkan_tipe_dengan_sisa_unit_nol(): void
    {
        $penuh = $this->buatTipe('Penuh', 1);
        $ada = $this->buatTipe('Ada', 2);

        $mulai = now()->addDays(3)->toDateString();
        $pelanggan = $this->buatPelanggan();
        // Satu pesanan dikonfirmasi menghabiskan unit tipe "Penuh"; satu lagi hanya mengurangi "Ada"
        $this->buatPesanan($pelanggan, $penuh, $mulai, 2, 'dikonfirmasi');
        $this->buatPesanan($pelanggan, $ada, $mulai, 2, 'berjalan');

        $r = $this->getJson(self::API."/katalog/tipe?tgl_mulai={$mulai}&durasi=2")->assertOk();
        $this->assertSame(['Ada'], collect($r->json('data'))->pluck('nama')->all());
        $this->assertSame(1, $r->json('data.0.sisa_unit'));
    }

    public function test_pesanan_menunggu_pembayaran_yang_lewat_batas_tidak_menahan_unit(): void
    {
        $tipe = $this->buatTipe('Satu', 1);
        $mulai = now()->addDays(3)->toDateString();
        $p = $this->buatPelanggan();

        $this->buatPesanan($p, $tipe, $mulai, 1, 'menunggu_pembayaran', now()->addMinutes(30));
        $this->getJson(self::API."/katalog/tipe?tgl_mulai={$mulai}&durasi=1")->assertJsonCount(0, 'data');

        Pemesanan::query()->update(['batas_pembayaran' => now()->subMinute()]);
        $this->getJson(self::API."/katalog/tipe?tgl_mulai={$mulai}&durasi=1")->assertJsonCount(1, 'data');
    }

    public function test_unit_dalam_servis_tidak_dihitung_dan_validasi_tanggal(): void
    {
        $tipe = $this->buatTipe('Servis', 1);
        Kendaraan::query()->update(['status' => 'dalam_perawatan']);
        $mulai = now()->addDays(3)->toDateString();

        $this->getJson(self::API."/katalog/tipe?tgl_mulai={$mulai}&durasi=1")->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/katalog/tipe?tgl_mulai='.now()->toDateString().'&durasi=1')->assertStatus(422);
        $this->getJson(self::API."/katalog/tipe?tgl_mulai={$mulai}&durasi=31")->assertStatus(422);
        $this->getJson(self::API.'/katalog/tipe')->assertStatus(422);
    }

    public function test_detail_tipe_dan_info(): void
    {
        $tipe = $this->buatTipe('Detail', 1);

        $this->getJson(self::API."/katalog/tipe/{$tipe->id_tipe}")
            ->assertOk()->assertJsonPath('data.nama', 'Detail')->assertJsonPath('data.review', []);
        $this->getJson(self::API.'/katalog/tipe/9999')->assertNotFound()->assertJsonPath('code', 'TIDAK_DITEMUKAN');
        $this->getJson(self::API.'/info')->assertOk()->assertJsonStructure(['data' => ['jam_operasional', 'batas_antar_gratis']]);
    }

    public function test_endpoint_admin_menolak_user_biasa_dan_guest(): void
    {
        $this->getJson(self::API.'/me')->assertUnauthorized()->assertJsonPath('code', 'BELUM_LOGIN');
    }

    // ---- bantu ----

    private function buatTipe(string $nama, int $unit): TipeKendaraan
    {
        $t = TipeKendaraan::create(['nama' => $nama, 'merk' => 'M', 'kursi' => 5, 'bahan_bakar' => 'bensin',
            'harga_sopir_per_hari' => 600000]);
        for ($i = 0; $i < $unit; $i++) {
            Kendaraan::create(['id_tipe' => $t->id_tipe, 'plat_nomor' => "N {$t->id_tipe}{$i} AB", 'tahun' => 2022,
                'warna' => 'Putih', 'transmisi' => 'manual', 'status' => 'tersedia']);
        }

        return $t;
    }

    private function buatPelanggan(): Pelanggan
    {
        $akun = Akun::create(['email' => 'p'.uniqid().'@mail.test', 'password' => 'abc12345']);

        return Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'P', 'no_hp' => '0812345678',
            'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang']);
    }

    private function buatPesanan(Pelanggan $p, TipeKendaraan $t, string $mulai, int $durasi, string $status, $batas = null): Pemesanan
    {
        return Pemesanan::create([
            'id_pelanggan' => $p->id_pelanggan, 'id_tipe' => $t->id_tipe, 'jenis_sewa' => 'dengan_sopir',
            'tgl_pesan' => now()->toDateString(), 'tgl_mulai' => $mulai, 'durasi' => $durasi,
            'tgl_kembali' => \Carbon\Carbon::parse($mulai)->addDays($durasi - 1)->toDateString(),
            'jam_serah' => '09:00', 'domisili' => 'malang_raya', 'total_biaya' => 600000 * $durasi,
            'status' => $status, 'batas_pembayaran' => $batas,
        ]);
    }
}
