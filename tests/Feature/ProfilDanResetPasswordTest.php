<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Pelanggan;
use App\Models\TokenAkun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** /profil, /profil/password, /auth/lupa-password, /auth/reset-password (Spek API bagian 4). */
class ProfilDanResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    /** @var list<string> isi email yang dikirim lewat Mail::raw selama tes */
    private array $surel = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::shouldReceive('raw')->andReturnUsing(function ($isi) {
            $this->surel[] = $isi;
        });
    }

    private function buatUser(array $ubah = []): Akun
    {
        $akun = Akun::create($ubah + ['email' => 'budi@mail.test', 'password' => 'lama12345', 'role' => 'user', 'email_terverifikasi_at' => now()]);
        Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'Budi', 'no_hp' => '081234567890', 'no_identitas' => '3573010000000001',
            'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang']);

        return $akun;
    }

    private function login(string $email = 'budi@mail.test', string $password = 'lama12345'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson(self::API.'/auth/login', ['email' => $email, 'password' => $password])->assertOk()->json('data.token');
    }

    private function panggil(string $metode, string $url, string $token, array $isi = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($metode, self::API.$url, $isi);
    }

    /** Ubah profil: POST multipart (form), seperti yang dikirim Swagger atau FormData. */
    private function kirimProfil(string $token, array $isi = [], array $berkas = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->withHeader('Accept', 'application/json')->post(self::API.'/profil', $isi + $berkas);
    }

    private function mintaToken(string $email = 'budi@mail.test'): string
    {
        $this->postJson(self::API.'/auth/lupa-password', ['email' => $email])->assertOk();
        preg_match('/^([A-Za-z0-9]{64})$/m', (string) end($this->surel), $m);

        return $m[1];
    }

    private function reset(string $token, array $ubah = [])
    {
        return $this->postJson(self::API.'/auth/reset-password', $ubah + ['email' => 'budi@mail.test', 'token' => $token, 'password' => 'baru12345', 'konfirmasi' => 'baru12345']);
    }

    // ---------------------------------------------------------------- profil

    public function test_lihat_profil_user_tanpa_data_sensitif(): void
    {
        $this->buatUser();

        $r = $this->panggil('GET', '/profil', $this->login())->assertOk();
        $r->assertJsonPath('data.nama', 'Budi')->assertJsonPath('data.email', 'budi@mail.test')->assertJsonPath('data.kota', 'Kota Malang')
            ->assertJsonPath('data.email_terverifikasi', true);
        $this->assertStringNotContainsString('password', $r->getContent());
        $this->assertStringNotContainsString('no_identitas', $r->getContent());
        $this->assertStringNotContainsString('3573010000000001', $r->getContent());
    }

    public function test_ubah_profil_user_post_email_diabaikan_dan_put_patch_tidak_ada(): void
    {
        $akun = $this->buatUser();
        $token = $this->login();

        $this->kirimProfil($token, ['nama' => 'Budi S', 'no_hp' => '085678901234', 'alamat' => 'Jl. Abaikan 1', 'provinsi' => 'Jawa Barat', 'kota' => 'Kota Bandung', 'email' => 'lain@mail.test'])
            ->assertOk()->assertJsonPath('data.nama', 'Budi S')->assertJsonPath('data.kota', 'Kota Bandung')->assertJsonPath('data.email', 'budi@mail.test')->assertJsonMissingPath('data.alamat');
        $this->assertSame('budi@mail.test', $akun->fresh()->email);

        // Hanya field yang dikirim berubah
        $this->kirimProfil($token, ['kota' => 'Kota Batu'])->assertOk()->assertJsonPath('data.kota', 'Kota Batu')->assertJsonPath('data.nama', 'Budi S');
        $p = $akun->pelanggan()->first();
        $this->assertSame(['Budi S', '085678901234', 'Jawa Barat', 'Kota Batu', '3573010000000001'], [$p->nama, $p->no_hp, $p->provinsi, $p->kota, $p->no_identitas]);

        // PUT dan PATCH /profil sudah dihapus (PHP tidak membaca PUT multipart)
        $this->panggil('PUT', '/profil', $token, ['kota' => 'Kota Lain'])->assertStatus(405);
        $this->panggil('PATCH', '/profil', $token, ['kota' => 'Kota Lain'])->assertStatus(405);
    }

    public function test_ubah_profil_validasi_dan_admin_hanya_nama(): void
    {
        $this->buatUser();
        $this->kirimProfil($this->login(), ['no_hp' => '123', 'nama' => ''])->assertStatus(422)
            ->assertJsonValidationErrors(['no_hp', 'nama'])->assertJsonPath('errors.no_hp.0', 'Nomor telepon atau WhatsApp tidak valid');

        Akun::create(['nama' => 'Adm', 'email' => 'adm@mail.test', 'password' => 'admin12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);
        $t = $this->login('adm@mail.test', 'admin12345');
        $this->panggil('GET', '/profil', $t)->assertOk()->assertJsonPath('data.role', 'admin')->assertJsonPath('data.nama', 'Adm');
        $this->kirimProfil($t, ['nama' => 'Adm Baru', 'kota' => 'diabaikan', 'email' => 'x@mail.test'])->assertOk()->assertJsonPath('data.nama', 'Adm Baru');
        $this->assertSame('adm@mail.test', Akun::where('role', 'admin')->first()->email);
    }

    // ---------------------------------------------------------------- foto profil

    public function test_foto_profil_tanpa_foto_dikirim_foto_lama_tetap(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();
        $token = $this->login();
        $this->panggil('GET', '/profil', $token)->assertJsonPath('data.foto', null);
        $this->kirimProfil($token, ['nama' => 'Budi Baru'])->assertOk()->assertJsonPath('data.foto', null);

        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $path = $akun->fresh()->foto;

        // Pembaruan tanpa foto: foto tetap ada di GET dan di respons
        $r = $this->kirimProfil($token, ['kota' => 'Kota Batu'])->assertOk();
        $this->assertSame($path, $akun->fresh()->foto);
        $r->assertJsonPath('data.foto', Storage::disk('public')->url($path));
        $this->panggil('GET', '/profil', $token)->assertJsonPath('data.foto', Storage::disk('public')->url($path));
        Storage::disk('public')->assertExists($path);
    }

    public function test_foto_profil_dengan_foto_disimpan_di_disk_publik_sebagai_url(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();

        $r = $this->kirimProfil($this->login(), ['nama' => 'Budi'], ['foto' => UploadedFile::fake()->image('a.webp')])->assertOk();

        $path = $akun->fresh()->foto;
        $this->assertStringStartsWith("profil/{$akun->id_akun}/", $path);
        Storage::disk('public')->assertExists($path);
        $r->assertJsonPath('data.foto', Storage::disk('public')->url($path));
    }

    public function test_ganti_foto_menghapus_berkas_lama_dari_disk(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();
        $token = $this->login();

        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $lama = $akun->fresh()->foto;
        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('b.png')])->assertOk();
        $baru = $akun->fresh()->foto;

        $this->assertNotSame($lama, $baru);
        Storage::disk('public')->assertMissing($lama);
        Storage::disk('public')->assertExists($baru);
        $this->assertCount(1, Storage::disk('public')->allFiles('profil'), 'Hanya satu foto per akun');
    }

    public function test_hapus_foto_mengosongkan_dan_menghapus_berkas(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();
        $token = $this->login();
        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $path = $akun->fresh()->foto;

        $this->kirimProfil($token, ['hapus_foto' => 'false'])->assertOk(); // false = tidak menghapus
        Storage::disk('public')->assertExists($path);

        $this->kirimProfil($token, ['hapus_foto' => 'true'])->assertOk()->assertJsonPath('data.foto', null);
        $this->assertNull($akun->fresh()->foto);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame([], Storage::disk('public')->allFiles('profil'));
    }

    public function test_foto_profil_admin(): void
    {
        Storage::fake('public');
        $admin = Akun::create(['nama' => 'Adm', 'email' => 'adm@mail.test', 'password' => 'admin12345', 'role' => 'admin', 'email_terverifikasi_at' => now()]);
        $t = $this->login('adm@mail.test', 'admin12345');

        $r = $this->kirimProfil($t, [], ['foto' => UploadedFile::fake()->image('a.jpg')])->assertOk()->assertJsonPath('data.nama', 'Adm');
        Storage::disk('public')->assertExists($admin->fresh()->foto);
        $r->assertJsonPath('data.foto', Storage::disk('public')->url($admin->fresh()->foto));
    }

    public function test_foto_profil_tipe_salah_dan_terlalu_besar_ditolak_tanpa_mengubah_apa_pun(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();
        $token = $this->login();

        $this->kirimProfil($token, ['nama' => 'Ganti'], ['foto' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors(['foto'])->assertJsonPath('errors.foto.0', 'Foto harus berformat jpg, jpeg, png, atau webp');
        $this->kirimProfil($token, ['nama' => 'Ganti'], ['foto' => UploadedFile::fake()->create('a.gif', 10, 'image/gif')])->assertStatus(422);
        $this->kirimProfil($token, ['nama' => 'Ganti'], ['foto' => UploadedFile::fake()->image('besar.jpg')->size(2049)])
            ->assertStatus(422)->assertJsonPath('errors.foto.0', 'Foto maksimal 2 MB');
        $this->assertSame('Budi', $akun->pelanggan()->first()->nama);
        $this->assertSame([], Storage::disk('public')->allFiles('profil'));

        $this->kirimProfil($token, ['nama' => 'Ganti'], ['foto' => UploadedFile::fake()->image('pas.jpg')->size(2048)])->assertOk();
        $this->assertCount(1, Storage::disk('public')->allFiles('profil'));
    }

    public function test_foto_yang_ditolak_tidak_menghapus_foto_lama(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();
        $token = $this->login();
        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $lama = $akun->fresh()->foto;

        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')])->assertStatus(422);

        $this->assertSame($lama, $akun->fresh()->foto);
        Storage::disk('public')->assertExists($lama);
    }

    public function test_berkas_baru_dibersihkan_dan_foto_lama_tetap_bila_penyimpanan_gagal(): void
    {
        Storage::fake('public');
        $akun = $this->buatUser();
        $token = $this->login();
        $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('a.jpg')])->assertOk();
        $lama = $akun->fresh()->foto;

        // Pembaruan kolom foto gagal setelah berkas baru tersimpan
        Akun::updating(function ($m) {
            if ($m->isDirty('foto')) {
                throw new \RuntimeException('gagal simpan');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->kirimProfil($token, [], ['foto' => UploadedFile::fake()->image('b.jpg')]);
            $this->fail('Seharusnya gagal');
        } catch (\RuntimeException) {
        } finally {
            Akun::flushEventListeners();
        }

        $this->assertSame($lama, $akun->fresh()->foto);
        $this->assertSame([$lama], Storage::disk('public')->allFiles('profil'), 'Berkas baru dibersihkan, berkas lama utuh');
    }

    public function test_profil_butuh_login_dan_admin_sementara_wajib_ganti_password_dulu(): void
    {
        $this->getJson(self::API.'/profil')->assertUnauthorized();

        Akun::create(['nama' => 'Adm', 'email' => 'adm@mail.test', 'password' => 'sementara1', 'role' => 'admin', 'wajib_ganti_password' => true, 'email_terverifikasi_at' => now()]);
        $t = $this->login('adm@mail.test', 'sementara1');
        $this->panggil('GET', '/profil', $t)->assertForbidden()->assertJsonPath('code', 'GANTI_PASSWORD_DULU');
    }

    // ---------------------------------------------------------------- ganti password

    public function test_ganti_password_mencabut_token_lain_dan_menurunkan_bendera(): void
    {
        $akun = $this->buatUser(['wajib_ganti_password' => true]);
        $aktif = $this->login();
        $lain = $this->login();
        $this->assertSame(2, $akun->tokens()->count());

        $this->panggil('PUT', '/profil/password', $aktif, ['password_lama' => 'lama12345', 'password_baru' => 'baru12345', 'konfirmasi' => 'baru12345'])
            ->assertOk()->assertJsonPath('message', 'Kata sandi berhasil diganti');

        $this->assertSame(1, $akun->tokens()->count());
        $this->assertFalse($akun->fresh()->wajib_ganti_password);
        $this->panggil('GET', '/profil', $aktif)->assertOk();
        $this->panggil('GET', '/profil', $lain)->assertUnauthorized();
        $this->login('budi@mail.test', 'baru12345');
    }

    public function test_endpoint_lama_auth_ganti_password_sudah_tidak_ada(): void
    {
        $this->buatUser();
        $t = $this->login();

        $this->assertContains($this->panggil('POST', '/auth/ganti-password', $t, ['password_lama' => 'lama12345', 'password_baru' => 'baru12345', 'konfirmasi' => 'baru12345'])->getStatusCode(), [404, 405]);
        $this->login('budi@mail.test', 'lama12345'); // kata sandi tidak berubah
    }

    public function test_ganti_password_salah_lama_atau_aturan_dilanggar(): void
    {
        $this->buatUser();
        $t = $this->login();

        $this->panggil('PUT', '/profil/password', $t, ['password_lama' => 'salah', 'password_baru' => 'baru12345', 'konfirmasi' => 'baru12345'])
            ->assertStatus(422)->assertJsonPath('errors.password_lama.0', 'Kata sandi lama salah');
        $this->panggil('PUT', '/profil/password', $t, ['password_lama' => 'lama12345', 'password_baru' => 'tanpaangka', 'konfirmasi' => 'tanpaangka'])
            ->assertStatus(422)->assertJsonPath('errors.password_baru.0', 'Kata sandi harus mengandung angka');
        $this->panggil('PUT', '/profil/password', $t, ['password_lama' => 'lama12345', 'password_baru' => 'baru12345', 'konfirmasi' => 'beda12345'])
            ->assertStatus(422)->assertJsonPath('errors.password_baru.0', 'Konfirmasi kata sandi tidak cocok');
        $this->panggil('PUT', '/profil/password', $t, ['password_lama' => 'lama12345', 'password_baru' => 'a1', 'konfirmasi' => 'a1'])
            ->assertStatus(422)->assertJsonPath('errors.password_baru.0', 'Kata sandi minimal 8 karakter');
        $this->login('budi@mail.test', 'lama12345');
    }

    // ---------------------------------------------------------------- lupa password

    public function test_lupa_password_terdaftar_dan_tidak_terdaftar_menjawab_sama(): void
    {
        $this->buatUser();

        $ada = $this->postJson(self::API.'/auth/lupa-password', ['email' => 'budi@mail.test'])->assertOk();
        $this->assertCount(1, $this->surel);
        $tidak = $this->postJson(self::API.'/auth/lupa-password', ['email' => 'hantu@mail.test'])->assertOk();

        $this->assertSame($ada->json(), $tidak->json());
        $this->assertCount(1, $this->surel, 'Email tidak terdaftar tidak mengirim apa pun');
        $this->assertSame(1, TokenAkun::where('jenis', 'reset_password')->count());
    }

    public function test_token_reset_disimpan_sebagai_hash_berlaku_60_menit_dan_token_lama_dibatalkan(): void
    {
        $akun = $this->buatUser();
        $pertama = $this->mintaToken();

        $baris = TokenAkun::where('jenis', 'reset_password')->firstOrFail();
        $this->assertSame(hash('sha256', $pertama), $baris->token_hash);
        $this->assertNotSame($pertama, $baris->token_hash);
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($baris->kedaluwarsa_at, false), 1);

        $kedua = $this->mintaToken();
        $this->assertNotSame($pertama, $kedua);
        $this->assertSame(1, TokenAkun::where('id_akun', $akun->id_akun)->where('jenis', 'reset_password')->count());
        $this->reset($pertama)->assertStatus(422)->assertJsonPath('code', 'TOKEN_RESET_TIDAK_VALID');
        $this->reset($kedua)->assertOk();
    }

    public function test_email_reset_memuat_tautan_frontend_dari_config_masa_berlaku_dan_token_cadangan(): void
    {
        config(['rental.frontend_url' => 'https://app.contoh.test']);
        $this->buatUser(['email' => 'budi+uji@mail.test']);
        $token = $this->mintaToken('budi+uji@mail.test');
        $isi = (string) end($this->surel);

        $tautan = "https://app.contoh.test/reset-password?token={$token}&email=budi%2Buji%40mail.test";
        $this->assertStringContainsString($tautan, $isi);
        $this->assertStringContainsString('60 menit', $isi);
        $this->assertGreaterThan(strpos($isi, $tautan), strrpos($isi, $token), 'Token mentah ada di bawah tautan');
        $this->assertSame(1, preg_match('/^'.$token.'$/m', $isi), 'Token mentah di barisnya sendiri');
    }

    public function test_lupa_password_dibatasi_5_per_menit_per_ip(): void
    {
        // Email berbeda-beda: yang diuji batas per IP (batas per email 3 per jam diuji di BatasLajuTest)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::API."/auth/lupa-password", ['email' => "hantu{$i}@mail.test"])->assertOk();
        }
        $this->postJson(self::API.'/auth/lupa-password', ['email' => 'hantu5@mail.test'])->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
    }

    public function test_reset_password_dibatasi_5_per_menit_dengan_hitungan_sendiri(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->reset('salah'.str_repeat('x', 59))->assertStatus(422);
        }
        $this->reset('salah'.str_repeat('x', 59))->assertStatus(429)->assertJsonPath('code', 'TERLALU_BANYAK_PERCOBAAN');
        // Jatah lupa-password tidak ikut habis
        $this->postJson(self::API.'/auth/lupa-password', ['email' => 'hantu@mail.test'])->assertOk();
    }

    public function test_lupa_password_akun_nonaktif_tidak_mengirim_dan_email_tidak_valid_422(): void
    {
        $this->buatUser(['aktif' => false]);
        $this->postJson(self::API.'/auth/lupa-password', ['email' => 'budi@mail.test'])->assertOk();
        $this->assertSame([], $this->surel);
        $this->postJson(self::API.'/auth/lupa-password', ['email' => 'bukan-email'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ---------------------------------------------------------------- reset password

    public function test_reset_berhasil_mencabut_semua_token_menurunkan_bendera_dan_menghapus_token_reset(): void
    {
        $akun = $this->buatUser(['wajib_ganti_password' => true, 'email_terverifikasi_at' => null]);
        // Akun belum terverifikasi tidak bisa login; token dibuat langsung untuk memeriksa pencabutan
        $akun->createToken('user');
        $akun->createToken('user');
        $token = $this->mintaToken();

        $this->reset($token)->assertOk()->assertJsonPath('message', 'Kata sandi berhasil direset, silakan login');

        $akun->refresh();
        $this->assertSame([0, false, true], [$akun->tokens()->count(), $akun->wajib_ganti_password, $akun->email_terverifikasi_at !== null]);
        $this->assertSame(0, TokenAkun::where('jenis', 'reset_password')->count());
        $this->postJson(self::API.'/auth/login', ['email' => 'budi@mail.test', 'password' => 'lama12345'])->assertUnauthorized();
        $this->login('budi@mail.test', 'baru12345');
    }

    public function test_reset_token_salah_email_salah_atau_aturan_password_dilanggar(): void
    {
        $this->buatUser();
        $this->buatUser(['email' => 'ani@mail.test']);
        $token = $this->mintaToken();

        $this->reset('salah'.str_repeat('x', 59))->assertStatus(422)->assertJsonPath('code', 'TOKEN_RESET_TIDAK_VALID');
        $this->reset($token, ['email' => 'ani@mail.test'])->assertStatus(422)->assertJsonPath('code', 'TOKEN_RESET_TIDAK_VALID');
        $this->reset($token, ['password' => 'tanpaangka', 'konfirmasi' => 'tanpaangka'])->assertStatus(422)->assertJsonPath('errors.password.0', 'Kata sandi harus mengandung angka');
        $this->reset($token, ['konfirmasi' => 'beda12345'])->assertStatus(422)->assertJsonPath('errors.password.0', 'Konfirmasi kata sandi tidak cocok');

        // Kesalahan di atas tidak menghanguskan token
        $this->reset($token)->assertOk();
    }

    public function test_reset_token_kedaluwarsa_setelah_60_menit(): void
    {
        $this->buatUser();
        $token = $this->mintaToken();

        $this->travel(59)->minutes();
        $this->reset($token, ['password' => 'tanpaangka', 'konfirmasi' => 'tanpaangka'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->travel(2)->minutes();
        $this->reset($token)->assertStatus(422)->assertJsonPath('code', 'TOKEN_RESET_KEDALUWARSA');
        $this->login('budi@mail.test', 'lama12345');
    }

    public function test_reset_token_hanya_sekali_pakai(): void
    {
        $this->buatUser();
        $token = $this->mintaToken();

        $this->reset($token)->assertOk();
        $this->reset($token, ['password' => 'lagi12345', 'konfirmasi' => 'lagi12345'])->assertStatus(422)->assertJsonPath('code', 'TOKEN_RESET_TIDAK_VALID');
        $this->reset($token, ['email' => 'hantu@mail.test'])->assertStatus(422)->assertJsonPath('code', 'TOKEN_RESET_TIDAK_VALID');
        $this->login('budi@mail.test', 'baru12345');
    }

    public function test_reset_menerima_nama_field_lama_password_confirmation(): void
    {
        $this->buatUser();
        $token = $this->mintaToken();

        $this->postJson(self::API.'/auth/reset-password', ['email' => 'budi@mail.test', 'token' => $token, 'password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertOk();
    }
}
