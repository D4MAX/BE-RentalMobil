<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Akun;
use App\Models\Pelanggan;
use App\Models\TokenAkun;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /** Daftar user baru: akun belum terverifikasi + pelanggan + tautan verifikasi 12 jam. */
    public function register(array $data): Akun
    {
        $akun = DB::transaction(function () use ($data) {
            $lama = Akun::where('email', $data['email'])->first();

            // Akun lama belum terverifikasi, umurnya < 30 hari, dan sudah lewat jendela tunggu: datanya digantikan.
            // Di dalam jendela pendaftaran ulang ditolak (422): kalau tidak, orang lain bisa memasang kata sandinya
            // sendiri di akun yang baru dibuat, lalu pemilik email mengeklik tautan verifikasi yang tampak sah.
            if ($lama && $lama->role === 'user' && $lama->email_terverifikasi_at === null
                && $lama->created_at->gt(now()->subDays(30))
                && $lama->created_at->lte(now()->subMinutes((int) config('rental.daftar_ulang_tunggu_menit')))) {
                $lama->delete(); // pelanggan dan token ikut terhapus (cascade)
            } elseif ($lama) {
                throw ValidationException::withMessages(['email' => ['Email sudah dipakai']]);
            }

            $akun = Akun::create([
                'email' => $data['email'],
                'password' => $data['password'],
                'role' => 'user',
            ]);

            Pelanggan::create([
                'id_akun' => $akun->id_akun,
                'nama' => $data['nama'],
                'no_hp' => $data['no_hp'],
                'provinsi' => $data['provinsi'],
                'kota' => $data['kota'],
            ]);

            return $akun;
        });

        $this->kirimTautanVerifikasi($akun);

        return $akun;
    }

    public function verifikasiEmail(string $token): void
    {
        DB::transaction(function () use ($token) {
            $record = $this->cariTokenValid($token, 'verifikasi_email');
            $record->update(['dipakai_at' => now()]);
            $record->akun()->update(['email_terverifikasi_at' => now()]);
        });
    }

    public function kirimUlangVerifikasi(string $email): void
    {
        $akun = Akun::where('email', $email)->first();
        if (! $akun) {
            throw new ApiException('Email tidak terdaftar', 'EMAIL_TIDAK_TERDAFTAR', 404);
        }
        if ($akun->email_terverifikasi_at) {
            throw new ApiException('Email sudah terverifikasi, silakan login', 'EMAIL_SUDAH_TERVERIFIKASI');
        }
        $this->kirimTautanVerifikasi($akun);
    }

    /** @return array{token: string, user: array, wajib_ganti_password: bool} */
    public function login(string $email, string $password): array
    {
        $akun = Akun::where('email', $email)->first();

        if (! $akun || ! Hash::check($password, $akun->password)) {
            throw new ApiException('Email atau kata sandi salah', 'KREDENSIAL_SALAH', 401);
        }
        if (! $akun->aktif) {
            throw new ApiException('Akun tidak aktif', 'AKUN_NONAKTIF', 403);
        }
        if (! $akun->isAdmin() && $akun->email_terverifikasi_at === null) {
            throw new ApiException('Email belum diverifikasi', 'EMAIL_BELUM_TERVERIFIKASI', 403);
        }

        $token = $akun->createToken($akun->role, [$akun->role], now()->addMinutes($akun->masaTokenMenit()))->plainTextToken;

        return [
            'token' => $token,
            'user' => ['id' => $akun->id_akun, 'nama' => $akun->nama_tampil, 'role' => $akun->role],
            'wajib_ganti_password' => $akun->wajib_ganti_password,
        ];
    }

    /** Token yang sedang dipakai tetap hidup; token lain milik akun ini dicabut. */
    public function gantiPassword(Akun $akun, string $passwordLama, string $passwordBaru): void
    {
        if (! Hash::check($passwordLama, $akun->password)) {
            throw ValidationException::withMessages(['password_lama' => ['Kata sandi lama salah']]);
        }

        DB::transaction(function () use ($akun, $passwordBaru) {
            $akun->update(['password' => $passwordBaru, 'wajib_ganti_password' => false]);

            $aktif = $akun->currentAccessToken();
            $lain = $akun->tokens();
            if ($aktif instanceof PersonalAccessToken) {
                $lain->where('id', '!=', $aktif->getKey());
            }
            $lain->delete();
        });
    }

    /**
     * Ubah profil sendiri. User: kolom di tabel pelanggan; admin: nama di tabel akun. Email tidak pernah diubah di sini.
     * Foto (satu per akun, kolom akun.foto, disk publik): berkas baru mengganti yang lama, $hapusFoto mengosongkan, selain itu
     * foto lama tetap. Berkas lama dihapus dari disk hanya setelah transaksi berhasil; berkas baru dihapus bila transaksi gagal.
     *
     * @param  array<string,mixed>  $d  field teks hasil ProfilRequest::validated()
     */
    public function ubahProfil(Akun $akun, array $d, ?UploadedFile $foto = null, bool $hapusFoto = false): Akun
    {
        $baru = null;
        $lama = null;

        try {
            DB::transaction(function () use ($akun, $d, $foto, $hapusFoto, &$baru, &$lama) {
                if ($akun->isAdmin()) {
                    if ($kolom = Arr::only($d, ['nama'])) {
                        $akun->update($kolom);
                    }
                } elseif ($kolom = Arr::only($d, ['nama', 'no_hp', 'provinsi', 'kota'])) {
                    $akun->pelanggan()->update($kolom);
                }

                if ($foto || $hapusFoto) {
                    $lama = $akun->foto;
                    $baru = $foto ? $foto->store("profil/{$akun->id_akun}", 'public') : null;
                    $akun->update(['foto' => $baru]);
                }
            });
        } catch (\Throwable $e) {
            if ($baru) {
                Storage::disk('public')->delete($baru); // berkas yatim bila transaksi dibatalkan
            }
            throw $e;
        }

        if ($lama) {
            Storage::disk('public')->delete($lama);
        }

        return $akun->fresh('pelanggan');
    }

    /**
     * Lupa kata sandi. Tidak pernah memberi tahu apakah email terdaftar: email tidak dikenal atau akun nonaktif
     * hanya tidak mengirim apa-apa. Token lama akun dibatalkan; token baru disimpan sebagai hash.
     */
    public function lupaPassword(string $email): void
    {
        $akun = Akun::where('email', $email)->where('aktif', true)->first();
        if (! $akun) {
            return;
        }

        $plain = Str::random(64);
        DB::transaction(function () use ($akun, $plain) {
            TokenAkun::where('id_akun', $akun->id_akun)->where('jenis', 'reset_password')->delete();
            TokenAkun::create([
                'id_akun' => $akun->id_akun,
                'jenis' => 'reset_password',
                'token_hash' => hash('sha256', $plain),
                'kedaluwarsa_at' => now()->addMinutes((int) config('rental.reset_menit')),
            ]);
        });

        $menit = (int) config('rental.reset_menit');
        $url = config('rental.frontend_url').'/reset-password?token='.$plain.'&email='.urlencode($akun->email);
        try {
            Mail::raw(
                "Kami menerima permintaan reset kata sandi untuk akun Anda.\n\nKlik tautan berikut untuk membuat kata sandi baru (berlaku {$menit} menit, sekali pakai):\n\n{$url}\n\nCadangan bila tautan tidak bisa dibuka, token reset:\n\n{$plain}\n\nAbaikan email ini bila bukan Anda yang meminta.",
                fn ($m) => $m->to($akun->email)->subject('Reset kata sandi akun rental mobil')
            );
        } catch (\Throwable $e) {
            // Jawaban tetap sama agar kegagalan kirim tidak membocorkan email terdaftar
            report($e);
        }
    }

    /**
     * Reset kata sandi dengan token. Berhasil: kata sandi baru, wajib_ganti_password turun, email dianggap terverifikasi
     * (token sampai ke kotak masuknya), semua token reset akun dihapus, dan SEMUA token Sanctum akun dicabut.
     */
    public function resetPassword(string $email, string $token, string $passwordBaru): void
    {
        DB::transaction(function () use ($email, $token, $passwordBaru) {
            $akun = Akun::where('email', $email)->where('aktif', true)->lockForUpdate()->first();
            $record = $akun ? TokenAkun::where('id_akun', $akun->id_akun)->where('jenis', 'reset_password')
                ->where('token_hash', hash('sha256', $token))->whereNull('dipakai_at')->lockForUpdate()->first() : null;

            if (! $record) {
                throw new ApiException('Token reset tidak valid', 'TOKEN_RESET_TIDAK_VALID', 422);
            }
            if ($record->kedaluwarsa_at->lte(now())) {
                throw new ApiException('Token reset sudah kedaluwarsa, minta token baru', 'TOKEN_RESET_KEDALUWARSA', 422);
            }

            $akun->update([
                'password' => $passwordBaru, 'wajib_ganti_password' => false,
                'email_terverifikasi_at' => $akun->email_terverifikasi_at ?? now(),
            ]);
            TokenAkun::where('id_akun', $akun->id_akun)->where('jenis', 'reset_password')->delete();
            $akun->tokens()->delete();
        });
    }

    private function kirimTautanVerifikasi(Akun $akun): void
    {
        // Tautan lama tidak berlaku lagi
        TokenAkun::where('id_akun', $akun->id_akun)->where('jenis', 'verifikasi_email')
            ->whereNull('dipakai_at')->update(['dipakai_at' => now()]);

        $plain = Str::random(64);
        TokenAkun::create([
            'id_akun' => $akun->id_akun,
            'jenis' => 'verifikasi_email',
            'token_hash' => hash('sha256', $plain),
            'kedaluwarsa_at' => now()->addHours(config('rental.verifikasi_jam')),
        ]);

        $url = config('rental.frontend_url').'/verifikasi-email?token='.$plain;
        try {
            Mail::raw(
                "Klik tautan berikut untuk memverifikasi email Anda (berlaku 12 jam):\n\n{$url}",
                fn ($m) => $m->to($akun->email)->subject('Verifikasi email akun rental mobil')
            );
        } catch (\Throwable $e) {
            // Akun dan tautan sudah tersimpan: gagal kirim bukan galat bagi pelanggan, ia bisa meminta kirim ulang
            report($e);
        }
    }

    private function cariTokenValid(string $token, string $jenis): TokenAkun
    {
        $record = TokenAkun::where('token_hash', hash('sha256', $token))
            ->where('jenis', $jenis)
            ->whereNull('dipakai_at')
            ->where('kedaluwarsa_at', '>', now())
            ->lockForUpdate()
            ->first();

        if (! $record) {
            throw new ApiException('Tautan tidak valid atau sudah kedaluwarsa', 'TOKEN_TIDAK_VALID', 422);
        }

        return $record;
    }
}
