<?php

namespace Tests\Feature;

use App\Models\Akun;
use App\Models\Pelanggan;
use App\Models\TokenAkun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/** Email verifikasi: kegagalan kirim tidak boleh jadi 500. Email tak terdaftar dijawab 404 (keputusan produk: pesan jelas untuk pengguna). */
class KirimEmailVerifikasiTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/v1';

    private function register(array $isi)
    {
        $server = $this->transformHeadersToServerVars(['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data']);

        return $this->call('POST', self::API.'/auth/register', $isi, [], [], $server);
    }

    private function dataDaftar(): array
    {
        return [
            'nama' => 'Budi', 'email' => 'budi@mail.test', 'no_hp' => '081234567890', 'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang',
            'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ];
    }

    private function akunBelumVerifikasi(string $email = 'budi@mail.test'): Akun
    {
        $akun = Akun::create(['email' => $email, 'password' => 'abc12345', 'role' => 'user']);
        Pelanggan::create(['id_akun' => $akun->id_akun, 'nama' => 'Budi', 'no_hp' => '0812345678', 'provinsi' => 'Jawa Timur', 'kota' => 'Kota Malang']);

        return $akun;
    }

    public function test_register_tetap_201_walau_email_gagal_dikirim_dan_akunnya_tersimpan(): void
    {
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP mati'));

        $this->register($this->dataDaftar())->assertCreated()->assertJsonPath('data.email', 'budi@mail.test');

        $this->assertSame(1, Akun::where('email', 'budi@mail.test')->count());
        $this->assertSame(1, TokenAkun::where('jenis', 'verifikasi_email')->count(), 'Tautan tersimpan: pelanggan bisa meminta kirim ulang');
    }

    public function test_kirim_ulang_untuk_email_tak_terdaftar_dijawab_404_dan_tidak_mengirim_apa_pun(): void
    {
        Mail::shouldReceive('raw')->never();

        $this->postJson(self::API.'/auth/kirim-ulang-verifikasi', ['email' => 'hantu@mail.test'])
            ->assertNotFound()->assertJsonPath('code', 'EMAIL_TIDAK_TERDAFTAR');

        $this->assertSame(0, TokenAkun::count());
    }

    public function test_kirim_ulang_untuk_akun_belum_terverifikasi_membuat_tautan_baru(): void
    {
        Mail::shouldReceive('raw')->once();
        $akun = $this->akunBelumVerifikasi();

        $this->postJson(self::API.'/auth/kirim-ulang-verifikasi', ['email' => 'budi@mail.test'])->assertOk();

        $this->assertSame(1, TokenAkun::where('id_akun', $akun->id_akun)->whereNull('dipakai_at')->count());
    }

    public function test_kirim_ulang_tetap_200_walau_email_gagal_dikirim(): void
    {
        Mail::shouldReceive('raw')->andThrow(new RuntimeException('SMTP mati'));
        $this->akunBelumVerifikasi();

        $this->postJson(self::API.'/auth/kirim-ulang-verifikasi', ['email' => 'budi@mail.test'])
            ->assertOk()->assertJsonPath('message', 'Tautan verifikasi dikirim');
    }

    public function test_kirim_ulang_untuk_akun_sudah_terverifikasi_dijawab_409_dan_tidak_mengirim_apa_pun(): void
    {
        Mail::shouldReceive('raw')->never();
        $this->akunBelumVerifikasi()->update(['email_terverifikasi_at' => now()]);

        $this->postJson(self::API.'/auth/kirim-ulang-verifikasi', ['email' => 'budi@mail.test'])
            ->assertStatus(409)->assertJsonPath('code', 'EMAIL_SUDAH_TERVERIFIKASI');
        $this->assertSame(0, TokenAkun::count());
    }
}
