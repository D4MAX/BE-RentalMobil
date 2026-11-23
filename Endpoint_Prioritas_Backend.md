# Endpoint Prioritas Backend: Sistem Rental Mobil

**Sumber:** Spek API 1.4 dan flowchart V22 · **Teknologi:** Laravel REST API + Sanctum · **Jumlah:** 56 operasi API, 2 tugas terjadwal

Dokumen ini mengurutkan endpoint dari yang harus dibuat paling dulu. Detail field, aturan validasi, dan kode galat ada di `Spek_API_Rental_Mobil.md`. Keputusan teknis backend (versi Laravel dan MySQL, nama tabel, pembuat PDF, antrean email, dokumentasi API, tempat deploy) masih menunggu persetujuan, sehingga nama berkas dan kelas di bawah bersifat usulan.

## 1. Cara membaca

- **Tahap:** P0 (fondasi), P1 (inti sewa), P2 (pelengkap), P3 (penunjang). Tiap tahap bisa didemokan sendiri setelah selesai.
- **Peran:** G = guest, U = user, A = admin, M = Midtrans (webhook).
- **Tabel:** tabel utama yang dibaca atau ditulis (nama dari ERD V22).
- **Tx:** endpoint wajib berjalan dalam database transaction (dengan kunci baris bila disebut).

## 2. Aturan yang berlaku di semua tahap

1. **Lapisan:** route (`routes/api.php`, awalan `v1`), controller tipis, FormRequest untuk validasi, Service untuk aturan bisnis, API Resource terpisah untuk user dan admin.
2. **Middleware:** `auth:sanctum`, peran (`user` atau `admin`), batas laju 5 per menit untuk login dan lupa kata sandi, dan `wajib_ganti_password` untuk admin dengan kata sandi sementara.
3. **Database transaction:** pembuatan pesanan, pengecekan ulang di webhook, serah terima, dan pengembalian. Pembuatan pesanan memakai `lockForUpdate()` pada tipe supaya siapa yang tiba lebih dulu mendapat jatah.
4. **Log aktivitas:** setiap aksi admin menulis ke `log_aktivitas` (siapa, apa, kapan). Aksi sistem memakai pelaku "Sistem".
5. **Galat:** format `{message, code, errors}` dengan kode bisnis dari Spek API bagian 15.
6. **Admin tidak bisa membatalkan pesanan.** Admin hanya menyetujui atau menolak pengajuan dari user.

## 3. P0: Fondasi

Selesai bila: user bisa mendaftar, memverifikasi email, login, melihat katalog menurut tanggal, dan admin bisa login dengan akun dari seeder.

| No  | Method | Path                           | Peran   | Tabel                                           | Catatan                                                                                            |
| --- | ------ | ------------------------------ | ------- | ----------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| 1   | POST   | `/auth/register`               | G       | akun, pelanggan, token_akun                     | Akun belum terverifikasi, kirim tautan 12 jam, tanpa token login                                   |
| 2   | POST   | `/auth/verifikasi-email`       | G       | akun, token_akun                                | Token sekali pakai, disimpan sebagai hash                                                          |
| 3   | POST   | `/auth/kirim-ulang-verifikasi` | G       | token_akun                                      | Maksimal 3 per jam                                                                                 |
| 4   | POST   | `/auth/login`                  | G       | akun                                            | Satu endpoint untuk semua peran; token user 3 hari, admin 8 jam; 429 setelah 5 percobaan per menit |
| 5   | POST   | `/auth/logout`                 | U, A    | akun                                            | Mencabut token saat ini                                                                            |
| 6   | GET    | `/me`                          | U, A    | akun, pelanggan                                 | Data akun yang sedang login                                                                        |
| 7   | GET    | `/katalog/tipe`                | G, U, A | tipe_kendaraan, tipe_foto, kendaraan, pemesanan | Filter tanggal, durasi, kursi, transmisi; menghitung sisa unit                                     |
| 8   | GET    | `/katalog/tipe/{id}`           | G, U, A | tipe_kendaraan, tipe_foto, review               | Tarif, foto, review yang ditampilkan                                                               |
| 9   | GET    | `/info`                        | G, U, A | pengaturan                                      | Jam operasional dan data kontak (teks)                                                             |

**Seeder P0:** akun admin (kata sandi sementara dari `.env`), pengaturan awal, dan data demo tipe dan unit (dipisah supaya tidak jalan di server asli).

**Tes minimal:** daftar lalu verifikasi lalu login berhasil; login sebelum verifikasi ditolak; token verifikasi kedaluwarsa ditolak; katalog tidak menampilkan tipe yang sisa unitnya 0.

## 4. P1: Inti sewa (memesan sampai selesai)

Selesai bila: satu pesanan bisa dibuat, dibayar lewat Midtrans sandbox, diserahkan, dikembalikan, dan menjadi Selesai, dengan dua user yang berebut unit terakhir ditangani benar.

| No  | Method | Path                                         | Peran | Tabel                                                                                     | Catatan                                                                                                               |
| --- | ------ | -------------------------------------------- | ----- | ----------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------- |
| 10  | POST   | `/pemesanan`                                 | U     | pemesanan, pembayaran, kendaraan, tipe_kendaraan                                          | **Tx + kunci tipe.** Cek tagihan lama, pesanan menunggu, sisa unit, jumlah sopir; buat pesanan dan transaksi Midtrans |
| 11  | GET    | `/pemesanan`                                 | U     | pemesanan                                                                                 | Tab berlangsung atau riwayat, 10 per halaman                                                                          |
| 12  | GET    | `/pemesanan/{id}`                            | U     | pemesanan, pembayaran, jaminan, pengembalian                                              | Objek `aksi` menentukan tombol; plat tampil setelah Berjalan                                                          |
| 13  | POST   | `/pemesanan/{id}/pembayaran`                 | U     | pembayaran                                                                                | Snap token baru selama belum lewat 1 jam                                                                              |
| 14  | POST   | `/pemesanan/{id}/batalkan`                   | U     | pemesanan                                                                                 | Hanya status menunggu pembayaran; unit dilepas                                                                        |
| 15  | GET    | `/pemesanan/{id}/nota`                       | U     | pemesanan, pembayaran                                                                     | PDF; hanya Dikonfirmasi ke atas                                                                                       |
| 16  | POST   | `/webhook/midtrans`                          | M     | pembayaran, pemesanan                                                                     | **Tx.** Cek tanda tangan, idempoten; settlement mengecek ulang sisa unit; expire membatalkan                          |
| 17  | GET    | `/admin/pemesanan`                           | A     | pemesanan                                                                                 | Daftar hanya status, filter dan pencarian                                                                             |
| 18  | GET    | `/admin/pemesanan/{id}`                      | A     | pemesanan, serah_terima, jaminan, pengembalian, log_aktivitas                             | Detail lengkap dengan riwayat aktivitas                                                                               |
| 19  | GET    | `/admin/pemesanan/{id}/unit-tersedia`        | A     | kendaraan, pemesanan                                                                      | Daftar plat yang disaring sistem; tidak pernah kosong untuk pesanan yang sudah dibayar                                |
| 20  | POST   | `/admin/pemesanan/{id}/serah-terima`         | A     | serah_terima, jaminan, kendaraan, pemesanan                                               | **Tx.** Jaminan hanya untuk lepas kunci; status Berjalan, unit disewa                                                 |
| 21  | POST   | `/admin/pemesanan/{id}/pengembalian/preview` | A     | pemesanan                                                                                 | Hitung ringkasan tanpa menyimpan                                                                                      |
| 22  | POST   | `/admin/pemesanan/{id}/pengembalian`         | A     | pengembalian, pengembalian_kondisi, pengembalian_foto, biaya_tambahan, jaminan, kendaraan | **Tx.** Denda, biaya tambahan (termasuk Biaya antar), jaminan, servis dari pengembalian                               |
| 23  | GET    | `/admin/tipe`                                | A     | tipe_kendaraan                                                                            | Daftar dengan jumlah unit                                                                                             |
| 24  | GET    | `/admin/tipe/{id}`                           | A     | tipe_kendaraan, tipe_foto                                                                 | Detail                                                                                                                |
| 25  | POST   | `/admin/tipe`                                | A     | tipe_kendaraan, tipe_foto                                                                 | Multipart, maksimal 3 foto                                                                                            |
| 26  | PUT    | `/admin/tipe/{id}`                           | A     | tipe_kendaraan, tipe_foto                                                                 | Tarif baru hanya untuk pesanan baru                                                                                   |
| 27  | DELETE | `/admin/tipe/{id}`                           | A     | tipe_kendaraan                                                                            | 409 bila masih punya unit atau pesanan                                                                                |
| 28  | GET    | `/admin/unit`                                | A     | kendaraan, detail_kendaraan                                                               | Filter tipe dan status                                                                                                |
| 29  | GET    | `/admin/unit/{id}`                           | A     | kendaraan, detail_kendaraan                                                               | Termasuk detail kendaraan                                                                                             |
| 30  | POST   | `/admin/unit`                                | A     | kendaraan, detail_kendaraan                                                               | Plat unik; status awal tersedia                                                                                       |
| 31  | PUT    | `/admin/unit/{id}`                           | A     | kendaraan, detail_kendaraan                                                               | Ubah data unit                                                                                                        |
| 32  | DELETE | `/admin/unit/{id}`                           | A     | kendaraan                                                                                 | 409 bila punya pemesanan                                                                                              |
| 33  | PATCH  | `/admin/unit/{id}/status`                    | A     | kendaraan, pemesanan                                                                      | Peringatan pesanan terdampak, tetap boleh dilanjutkan (tanggung jawab admin)                                          |

**Tugas terjadwal P1 (tiap menit):** pesanan menunggu pembayaran yang lewat 1 jam menjadi Dibatalkan, pembayaran gagal, unit dilepas.

**Tes minimal:** dua user berebut unit terakhir (yang kedua ditolak tanpa tagihan); notifikasi settlement dua kali tidak mengubah apa pun (idempoten); settlement terlambat saat jatah habis dibatalkan dan direfund; serah terima tanpa jaminan ditolak untuk lepas kunci; denda dihitung per hari kalender.

## 5. P2: Pelengkap (aturan yang menempel pada alur inti)

Selesai bila: pembatalan dan refund, tagihan dan jaminan, review, dan servis berjalan.

| No  | Method | Path                                       | Peran | Tabel                                  | Catatan                                                                                                 |
| --- | ------ | ------------------------------------------ | ----- | -------------------------------------- | ------------------------------------------------------------------------------------------------------- |
| 34  | POST   | `/pemesanan/{id}/ajukan-pembatalan`        | U     | pemesanan, pembayaran                  | Hanya Dikonfirmasi dan paling lambat H-1; alasan wajib; untuk VA wajib bank, no rekening, nama rekening |
| 35  | POST   | `/pemesanan/{id}/review`                   | U     | review                                 | Hanya Selesai, sekali per pesanan                                                                       |
| 36  | POST   | `/admin/pemesanan/{id}/setujui-pembatalan` | A     | pemesanan, pembayaran                  | **Tx.** Dibatalkan, unit dilepas; refund QRIS lewat Midtrans, VA menunggu transfer admin                |
| 37  | POST   | `/admin/pemesanan/{id}/tolak-pembatalan`   | A     | pemesanan                              | Kembali ke status sebelumnya                                                                            |
| 38  | POST   | `/admin/pemesanan/{id}/refund-selesai`     | A     | pembayaran                             | Hanya untuk VA yang menunggu transfer                                                                   |
| 39  | POST   | `/admin/pemesanan/{id}/tagihan/lunas`      | A     | pembayaran, pemesanan                  | Status Selesai dengan tagihan belum lunas                                                               |
| 40  | POST   | `/admin/pemesanan/{id}/jaminan/kembalikan` | A     | jaminan                                | 409 bila tagihan belum lunas                                                                            |
| 41  | PUT    | `/admin/pemesanan/{id}/catatan`            | A     | pemesanan                              | Catatan internal admin                                                                                  |
| 42  | PATCH  | `/admin/review/{id}`                       | A     | review                                 | Tampil atau sembunyikan di katalog                                                                      |
| 43  | GET    | `/admin/servis`                            | A     | perawatan, detail_perawatan            | Ringkasan, sedang servis, riwayat                                                                       |
| 44  | GET    | `/admin/servis/status-unit`                | A     | kendaraan, perawatan                   | Aman, segera, perlu servis                                                                              |
| 45  | POST   | `/admin/servis`                            | A     | perawatan, kendaraan                   | Ditolak bila unit disewa; peringatan pesanan terdampak                                                  |
| 46  | POST   | `/admin/servis/{id}/selesai`               | A     | perawatan, detail_perawatan, kendaraan | Menghitung jadwal servis berikutnya                                                                     |
| 47  | POST   | `/admin/servis/{id}/batal`                 | A     | perawatan, kendaraan                   | Unit kembali tersedia                                                                                   |
| 48  | PUT    | `/admin/servis/interval`                   | A     | pengaturan                             | Interval km, bawaan 5000                                                                                |

**Tugas terjadwal P2 (harian):** pesanan Dikonfirmasi yang lewat tanggal kembali tanpa serah terima ditutup menjadi Dibatalkan (alasan tidak diambil), pembayaran tetap lunas, tanpa refund.

**Tes minimal:** pengajuan setelah H-1 ditolak; menyetujui pengajuan melepas unit dan menandai refund; admin tidak punya endpoint pembatalan sendiri; jaminan tidak bisa dikembalikan sebelum tagihan lunas.

## 6. P3: Penunjang

Selesai bila: laporan, dashboard, profil, dan pemulihan kata sandi berjalan.

| No  | Method | Path                   | Peran | Tabel                           | Catatan                                                                              |
| --- | ------ | ---------------------- | ----- | ------------------------------- | ------------------------------------------------------------------------------------ |
| 49  | GET    | `/profil`              | U     | pelanggan, akun                 | Email hanya dibaca                                                                   |
| 50  | POST   | `/profil`              | U, A  | pelanggan, akun                 | multipart/form-data; nama, WhatsApp, provinsi, kota, foto (admin: nama saja); Malang Raya dihitung server |
| 51  | PUT    | `/profil/password`     | U, A  | akun                            | Minimal 8 karakter dan ada angka; mengakhiri kata sandi sementara                    |
| 52  | POST   | `/auth/lupa-password`  | G     | token_akun                      | Tautan 1 jam                                                                         |
| 53  | POST   | `/auth/reset-password` | G     | akun, token_akun                | Sekaligus memverifikasi email                                                        |
| 54  | GET    | `/admin/dashboard`     | A     | pemesanan, kendaraan, perawatan | Hitungan status, daftar tugas admin, pengingat servis                                |
| 55  | GET    | `/admin/laporan`       | A     | pemesanan, pembayaran           | Default 7 hari, periode dan status; pendapatan hanya Dikonfirmasi, Berjalan, Selesai |
| 56  | GET    | `/admin/laporan/pdf`   | A     | pemesanan, pembayaran           | PDF; 409 bila data kosong                                                            |

**Tugas terjadwal P3 (harian):** akun belum terverifikasi yang berumur lebih dari 30 hari dihapus.

## 7. Urutan pengerjaan dan ketergantungan

```
P0 fondasi  ->  P1 inti sewa  ->  P2 pelengkap  ->  P3 penunjang
(akun, katalog)  (pesan, bayar,    (batal, refund,   (laporan, dashboard,
                  serah, kembali)   servis, review)   profil, reset)
```

- **P1 bergantung pada P0:** semua endpoint butuh login dan katalog.
- **P2 bergantung pada P1:** pembatalan, refund, tagihan, dan servis memakai pesanan dan unit dari P1.
- **P3 boleh dikerjakan paralel** dengan P2 karena tidak mengubah alur pesanan.
- **Webhook Midtrans (no 16)** perlu server yang bisa dijangkau Midtrans (server publik atau tunnel) sejak P1.
