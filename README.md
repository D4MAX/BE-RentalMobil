<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="300" alt="Laravel"></a></p>

<p align="center">
<img src="https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white" alt="Laravel 13">
<img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.3+">
<img src="https://img.shields.io/badge/MySQL-4479A1?logo=mysql&logoColor=white" alt="MySQL">
<img src="https://img.shields.io/badge/Midtrans-sandbox-0B5FFF" alt="Midtrans">
</p>

# Backend Sistem Rental Mobil

Backend untuk sistem penyewaan mobil PT GantengnyaJatim (Malang). Dibuat untuk proyek capstone. Isinya API REST yang dipakai pelanggan untuk memesan dan membayar sewa, dan admin untuk mengelola armada, serah terima, pengembalian, servis, dan laporan.

Teknologi: Laravel 13 (PHP 8.3+), MySQL, Laravel Sanctum, Midtrans (pembayaran).

Dokumen lain di repo ini:

- `Spek_API_Rental_Mobil.md`: spesifikasi API
- `PRD_Sistem_Rental_Mobil_V8.md`: kebutuhan produk
- `openapi.yaml`: spesifikasi OpenAPI, dibaca Swagger UI di `/docs`

## Menjalankan di komputer sendiri

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Buat database MySQL, isi `DB_*` di `.env`, lalu:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

API jalan di `http://127.0.0.1:8000/api/v1`. Untuk tugas terjadwal (pesanan kedaluwarsa, refund tertunda, pembersihan sampah), jalankan di terminal lain:

```bash
php artisan schedule:work
```

Di server, pakai cron: `* * * * * php /path/artisan schedule:run`.

## Mencoba lewat Swagger

1. Buka `http://127.0.0.1:8000/docs`. Pakai alamat ini juga untuk API.
2. Panggil `POST /api/v1/auth/login` dengan akun admin dari seeder: `admin@rental.test` / `Admin12345`.
3. Salin `data.token`, klik **Authorize**, tempel token tanpa kata `Bearer`.
4. Admin baru wajib ganti kata sandi dulu lewat `PUT /api/v1/profil/password`. Sebelum itu, endpoint lain menjawab 403 `GANTI_PASSWORD_DULU`.

Seeder hanya membuat satu akun admin. Akun pelanggan dibuat lewat `POST /api/v1/auth/register`, lalu diverifikasi dengan tautan di email.

`/docs` dan `/openapi.yaml` hanya aktif bila `APP_ENV` bukan `production`.

Masa berlaku token: admin 30 menit, pelanggan 60 menit (`TOKEN_ADMIN_MENIT` dan `TOKEN_USER_MENIT`). Selama ada permintaan yang berhasil, masa berlaku diperpanjang. Token yang habis dijawab 401 `BELUM_LOGIN`.

## Email

Secara bawaan `MAIL_MAILER=log`, jadi email tidak terkirim. Isinya ditulis ke `storage/logs/laravel.log`. Token reset kata sandi dan token verifikasi bisa diambil dari sana.

Untuk mengirim email sungguhan, pakai `MAIL_MAILER=smtp` dan isi `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`. Tautan di email memakai `FRONTEND_URL`.

## Pembayaran

`PEMBAYARAN_GATEWAY` punya dua nilai:

- `palsu` (bawaan): tanpa jaringan dan tanpa kunci. Cocok untuk lokal.
- `midtrans`: Midtrans asli, sandbox bila `MIDTRANS_IS_PRODUCTION=false`.

Untuk mencoba Midtrans sandbox:

1. Ambil Server Key dan Client Key sandbox di dashboard Midtrans (Settings, Access Keys).
2. Isi `.env`: `PEMBAYARAN_GATEWAY=midtrans`, `MIDTRANS_SERVER_KEY`, `MIDTRANS_CLIENT_KEY`, lalu `php artisan config:clear`.
3. Jalankan `ngrok http 8000`, lalu isi Payment Notification URL di dashboard dengan `https://<alamat-ngrok>/api/v1/webhook/midtrans`.
4. Buat pesanan lewat `POST /api/v1/pemesanan`. Respons memuat `snap_token`.
5. Buka `https://app.sandbox.midtrans.com/snap/v2/vtweb/<snap_token>` untuk membayar, lalu selesaikan di simulator `https://simulator.sandbox.midtrans.com`.
6. Cek `GET /api/v1/pemesanan/{id}`: status harus `dikonfirmasi`.

Tanpa Midtrans, kirim webhook sendiri. Tanda tangannya `sha512(order_id + status_code + gross_amount + MIDTRANS_SERVER_KEY)`.

## Tes

Tes memakai database terpisah, `rental_mobil_test`. Buat dulu dengan `CREATE DATABASE rental_mobil_test`.

```bash
php artisan test
```

Jangan jalankan dua proses tes bersamaan. Keduanya memakai database yang sama dan hasilnya jadi kacau.

## Mengosongkan data sebelum demo

```bash
php artisan demo:reset
```

Perintah ini menjalankan `migrate:fresh --seed` dan menghapus semua berkas unggahan. Semua data hilang. Perintah meminta konfirmasi, kecuali dengan `--force`.

Perintah ini menolak jalan di production, dan hanya mau jalan di database yang namanya sama dengan `DEMO_RESET_DATABASE` (bawaan `rental_mobil`).

## Tugas terjadwal

| Perintah                          | Jadwal        | Fungsi                                                    |
| --------------------------------- | ------------- | --------------------------------------------------------- |
| `pemesanan:batalkan-kedaluwarsa`  | tiap menit    | membatalkan pesanan yang tidak dibayar                    |
| `refund:ulangi-tertunda`          | tiap 10 menit | mengulang refund Midtrans yang tertinggal                 |
| `pemesanan:tutup-tidak-diambil`   | 00:10         | menutup pesanan yang tidak diambil                        |
| `sampah:bersihkan`                | 02:00         | membuang permanen data di tempat sampah lewat HARI_SAMPAH hari (bawaan 30) |
| `akun:bersihkan-belum-verifikasi` | 02:30         | menghapus akun yang tidak diverifikasi lebih dari 30 hari |

## Tempat sampah

Tipe dan unit yang dihapus tidak langsung hilang. Admin bisa melihat daftarnya di `GET /admin/sampah`, memulihkannya lewat `POST /admin/tipe/{id}/pulihkan` atau `/admin/unit/{id}/pulihkan`, atau menghapusnya permanen dengan `DELETE /admin/sampah/tipe/{id}?konfirmasi=true`.

Unit yang punya riwayat pesanan, serah terima, atau servis boleh dihapus, tetapi tidak pernah dibuang permanen. Unit yang sedang disewa atau sedang servis tidak bisa dihapus.

Bila membuat tipe atau unit yang bentrok dengan data di tempat sampah (nama dan merk, plat, nomor rangka, nomor mesin, BPKB), server menjawab 422 `TIPE_ADA_DI_SAMPAH` atau `UNIT_ADA_DI_SAMPAH` beserta data lamanya, supaya frontend bisa menawarkan pulihkan atau hapus permanen.

## Batas laju

Lewat batas dijawab 429 `TERLALU_BANYAK_PERCOBAAN` dengan header `Retry-After`.

| Endpoint               | Batas                                    |
| ---------------------- | ---------------------------------------- |
| login                  | 5 per menit per IP, 10 per jam per email |
| lupa kata sandi        | 5 per menit per IP, 3 per jam per email  |
| reset kata sandi       | 5 per menit per IP                       |
| daftar                 | 10 per jam per IP                        |
| kirim ulang verifikasi | 3 per jam per email dan IP               |

## Catatan untuk production

- `APP_ENV=production` dan `APP_DEBUG=false`. Aplikasi menolak jalan bila `APP_DEBUG=true`, `MAIL_MAILER=log`, atau `PEMBAYARAN_GATEWAY` bukan `midtrans`.
- Isi `ADMIN_PASSWORD` dengan kata sandi sendiri sebelum `php artisan migrate --seed`. Seeder menolak kata sandi bawaan di production.
- Isi `TRUSTED_PROXIES` dengan IP proxy bila server di belakang nginx atau Cloudflare. Kalau kosong, semua pengguna terbaca satu IP dan berbagi jatah batas laju.
- Isi `CORS_ALLOWED_ORIGINS` dengan alamat frontend, dipisah koma, tanpa garis miring di akhir. Kalau kosong, nilainya `FRONTEND_URL`.
- Di `php.ini`, set `post_max_size` sekitar 64M dan `upload_max_filesize` sekitar 8M.

## Yang belum dikerjakan

- Endpoint admin untuk melihat dan mengunduh foto kondisi serah terima dan foto pengembalian. Fotonya sudah tersimpan di disk privat.
- Alur perpanjang sewa di sisi server. Saat ini `bisa_perpanjang` hanya memberi tahu frontend kapan tombolnya muncul.
