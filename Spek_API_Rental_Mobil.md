# Spesifikasi API: Sistem Rental Mobil

**Versi:** 1.4 (mengikuti PRD Sistem V8 dan flowchart V22) · **Teknologi:** Laravel REST API + Sanctum · **Basis URL:** `/api/v1`

Dokumen ini menggantikan `openapi.yaml` dan `mock-server.php` yang lama. Keduanya sudah usang (masih ada pemesanan manual admin, status `disetujui`, dan denda 50%).

## 1. Konvensi

- **Format:** JSON (`Content-Type: application/json`). Unggah berkas memakai `multipart/form-data`.
- **Autentikasi:** `Authorization: Bearer {token}` (Sanctum). Tiap token membawa peran `user` atau `admin`.
- **Penamaan:** snake_case, mengikuti nama kolom di ERD (mis. `plat_nomor`, `tgl_mulai`, `jam_serah`).
- **Tanggal dan jam:** tanggal `YYYY-MM-DD`, jam `HH:mm`, waktu lengkap ISO 8601 zona Asia/Jakarta. Uang berupa bilangan bulat rupiah.
- **Bentuk respons sukses:** `{"data": ...}`. Daftar: `{"data": [...], "meta": {"page", "per_page", "total"}}` (bawaan 10 per halaman, parameter `page` dan `per_page`).
- **Bentuk respons galat:**
  ```json
  {"message": "Mobil sudah dipesan pada tanggal tersebut", "code": "UNIT_TIDAK_TERSEDIA", "errors": {"field": ["..."]}}
  ```
  `errors` hanya ada pada 422.
- **Kode status:** 200, 201, 204 (berhasil); 401 (belum login); 403 (peran atau akun tidak boleh); 404; 409 (bentrok aturan bisnis); 422 (validasi); 429 (terlalu banyak percobaan).
- **Zona waktu dan aturan hari:** batas pengembalian adalah tanggal kembali pukul 23.59.

## 2. Enum

| Nama | Nilai |
|---|---|
| `pemesanan.status` | `menunggu_pembayaran`, `dikonfirmasi`, `berjalan`, `selesai`, `pengajuan_pembatalan`, `dibatalkan` |
| `pembayaran.jenis` | `sewa`, `tambahan`, `refund` |
| `pembayaran.status` | `menunggu`, `lunas`, `gagal`, `dikembalikan` |
| `kendaraan.status` | `tersedia`, `dalam_perawatan`, `tidak_tersedia` (status "disewa" tidak disimpan, dihitung dari pesanan `berjalan`) |
| `perawatan.status` | `berlangsung`, `selesai`, `dibatalkan` |
| `jenis_sewa` | `dengan_sopir`, `lepas_kunci` |
| `transmisi` | `manual`, `matic` |
| `status_kepemilikan` | `milik_perusahaan`, `titipan` |
| `kondisi` | `baik`, `lecet`, `penyok`, `retak`, `rusak` |
| `bbm` | `penuh`, `tiga_perempat`, `setengah`, `seperempat`, `hampir_kosong` |
| `kebersihan` | `bersih`, `kotor_biasa`, `sangat_kotor` |
| `bagian` (8) | `depan`, `belakang`, `sisi_kiri`, `sisi_kanan`, `atap`, `kaca_lampu`, `ban_velg`, `interior` |
| `tingkat_servis` | `ringan`, `sedang`, `perlu_bengkel` |
| `metode_bayar_tagihan` | `belum_dibayar`, `tunai`, `transfer` |

## 3. Matriks akses

| Kelompok | Guest | User | Admin |
|---|---|---|---|
| `/auth/*` (register, verifikasi email, login, lupa dan reset kata sandi) | Ya | - | - |
| `/auth/logout`, `/me`, `/profil`, `/profil/password` | - | Ya | Ya |
| `/katalog/*`, `/info` | Ya | Ya | Ya |
| `/pemesanan/*` | - | Ya (milik sendiri) | - |
| `/admin/*` | - | - | Ya |
| `/webhook/midtrans` | Midtrans (tanda tangan) | - | - |

## 4. Autentikasi dan akun

### POST `/auth/register` (guest)
Membuat akun user. Admin tidak dibuat lewat API (dibuat pengembang lewat database). Body `multipart/form-data` (form, bukan JSON); tidak ada field file atau berkas identitas, semua field berupa teks.

| Field | Aturan |
|---|---|
| `nama` | wajib, maks 100 |
| `email` | wajib, format email, unik |
| `no_hp` | wajib, nomor telepon atau WhatsApp Indonesia (hanya data kontak) |
| `provinsi`, `kota` | wajib, dipilih dari daftar wilayah (daftar disimpan di tampilan, server menghitung kategorinya) |
| `password` | wajib, minimal 8 karakter dan ada angka, ada `password_confirmation` |

- **201:** `{"data": {"id": 1, "nama": "...", "email": "..."}, "message": "Cek email Anda untuk verifikasi"}`. Akun dibuat belum terverifikasi dan tautan verifikasi dikirim lewat email (berlaku 12 jam). Tidak ada token; user belum bisa login.
- **422:** email sudah dipakai (`errors.email`). Pengecualian: akun lama belum terverifikasi, berumur kurang dari 30 hari, **dan sudah lebih dari 60 menit sejak dibuat** (`DAFTAR_ULANG_TUNGGU_MENIT`); datanya digantikan. Di dalam 60 menit pertama pendaftaran ulang ditolak, supaya orang lain tidak bisa memasang kata sandinya sendiri di akun yang baru dibuat (pemilik email lalu mengeklik tautan verifikasi yang tampak sah). Akun belum terverifikasi lebih dari 30 hari dihapus oleh `akun:bersihkan-belum-verifikasi`.

### POST `/auth/verifikasi-email` (guest)
Body: `token`. **200** email terverifikasi (tampilan mengarahkan ke Login). **422** `TOKEN_TIDAK_VALID`: token salah, kedaluwarsa (12 jam), atau sudah dipakai.

### POST `/auth/kirim-ulang-verifikasi` (guest)
Body: `email`. **200** tautan baru dikirim (tautan lama tidak berlaku); gagal kirim email tidak menjadi galat. **429** lebih dari 3 kali per jam. **404** `EMAIL_TIDAK_TERDAFTAR`. **409** `EMAIL_SUDAH_TERVERIFIKASI` bila akun sudah terverifikasi (tidak ada email yang dikirim; arahkan ke Login).

### POST `/auth/login`
Body: `email`, `password`.
- **200:** `{"data": {"token": "...", "user": {"id", "nama", "role"}, "wajib_ganti_password": false}}`. Satu endpoint login untuk semua peran; peran menentukan tujuan di tampilan. Masa berlaku token: **60 menit untuk user, 30 menit untuk admin** (config `token_user_menit` dan `token_admin_menit`, diatur lewat `TOKEN_USER_MENIT` dan `TOKEN_ADMIN_MENIT` di `.env`), berlaku sebagai **idle timeout** tanpa refresh token: setiap permintaan terautentikasi yang **berhasil** (status di bawah 400) memperpanjang token sampai masa berlaku penuh lagi, tetapi hanya bila sisa waktunya tinggal separuh atau kurang (paling banyak satu UPDATE per setengah masa berlaku). Permintaan gagal (4xx, 5xx) dan token yang sudah kedaluwarsa tidak memperpanjang. Token kedaluwarsa dijawab **401** `BELUM_LOGIN` (`{"message": "Belum login", "code": "BELUM_LOGIN"}`, sama seperti token tidak valid): tampilan mengarahkan ke Login. Untuk admin dengan kata sandi sementara `wajib_ganti_password` bernilai `true`, dan semua endpoint lain mengembalikan 403 `GANTI_PASSWORD_DULU` sampai kata sandi diganti.
- **401** `KREDENSIAL_SALAH`: email atau kata sandi salah.
- **403** `AKUN_NONAKTIF`: akun admin nonaktif (`akun.aktif = false`).
- **403** `EMAIL_BELUM_TERVERIFIKASI`: akun user belum memverifikasi email (tampilan menawarkan Kirim ulang).
- **429:** lebih dari 5 percobaan per menit.

### POST `/auth/logout`
Mencabut token saat ini. **204.**

### POST `/auth/lupa-password`
Body: `email`. Selalu **200** dengan pesan yang sama (`"Jika email terdaftar, instruksi reset kata sandi telah dikirim"`), email terdaftar atau tidak (anti enumerasi); akun nonaktif juga tidak dikirimi apa pun.
- Bila email terdaftar dan aktif: dikirim email berisi tautan `FRONTEND_URL/reset-password?token=<token>&email=<email-urlencoded>` (nilai `FRONTEND_URL` dari konfigurasi) dan, di bawahnya, token mentah 64 karakter di barisnya sendiri sebagai cadangan. Token berlaku **60 menit**, sekali pakai, disimpan sebagai hash SHA-256 di `token_akun` (`jenis = reset_password`). Permintaan baru membatalkan token lama akun itu.
- **422** bila `email` kosong atau formatnya salah. **429** lebih dari 5 permintaan per menit per IP.

### POST `/auth/reset-password`
Body: `email`, `token`, `password`, `konfirmasi` (nama lama `password_confirmation` tetap diterima). Kata sandi baru minimal 8 karakter dan ada angka (aturan sama dengan register).
- **200:** kata sandi baru disimpan, `wajib_ganti_password` turun, email dianggap terverifikasi, token reset akun dihapus, dan **semua** token login akun dicabut (login ulang).
- **422** `TOKEN_RESET_TIDAK_VALID`: token salah, email tidak cocok dengan token, atau token sudah dipakai. **422** `TOKEN_RESET_KEDALUWARSA`: lewat 60 menit. **422** `VALIDASI_GAGAL`: kata sandi tidak memenuhi aturan atau konfirmasi tidak cocok (token tidak hangus).
- **429:** lebih dari 5 percobaan per menit per IP (hitungan terpisah dari lupa-password).

### PUT `/profil/password` (user, admin)
Body: `password_lama`, `password_baru` (minimal 8 karakter dan ada angka), `konfirmasi`.
- **200** berhasil: `wajib_ganti_password` turun (mengakhiri kata sandi sementara admin), token login **lain** milik akun dicabut, token yang sedang dipakai tetap hidup. **422** `password_lama` salah, kata sandi baru tidak memenuhi aturan, atau konfirmasi tidak cocok. Boleh dipanggil admin yang masih `wajib_ganti_password`.

### GET `/profil` (user, admin)
Data akun sendiri. **200:** `{"data": {"id", "nama", "email", "role", "no_hp", "provinsi", "kota", "foto", "email_terverifikasi"}}`. Tanpa kata sandi dan tanpa `no_identitas` (diisi admin saat serah terima). Admin: kolom kontak `null`. `foto` berisi URL foto profil (disk publik), `null` bila belum ada. Admin yang masih `wajib_ganti_password` dijawab 403 `GANTI_PASSWORD_DULU`.

### POST `/profil` (user, admin)
`multipart/form-data` (form), bukan JSON: PHP tidak membaca PUT multipart, jadi `PUT` dan `PATCH /profil` **tidak ada lagi** (405). Semua field opsional; hanya field yang dikirim yang diubah. User: `nama` (maks 100), `no_hp` (format sama dengan register), `provinsi`, `kota` (tabel `pelanggan`). Admin: hanya `nama` (tabel `akun`). `email`, `no_identitas`, dan `role` **tidak bisa** diubah di sini (diabaikan).
- **Foto** (satu per akun, disimpan di kolom `akun.foto`, disk **publik**, ditampilkan sebagai URL): `foto` (berkas jpg, jpeg, png, atau webp, maks 2 MB). Tanpa `foto`, foto lama tetap dan tetap muncul di GET dan respons. Dengan `foto`, foto lama diganti dan berkasnya dihapus dari disk. `hapus_foto=true` mengosongkan foto (`null`) dan menghapus berkasnya; bila `foto` dan `hapus_foto=true` dikirim bersamaan, `foto` yang berlaku. Berkas baru dibersihkan bila proses gagal.
- **200:** isi sama dengan GET `/profil`. **422** validasi (tipe berkas bukan jpg, jpeg, png, webp; ukuran lebih dari 2 MB).

### GET `/me`
**200:** `{"data": {"id", "nama", "email", "role", "no_hp", "provinsi", "kota"}}`.

### GET `/profil` dan POST `/profil` (user)
Field: `nama`, `no_hp`, `provinsi`, `kota`, `foto` (berkas, maks 2 MB, jpg, jpeg, png, atau webp), `hapus_foto`. **Email tidak bisa diubah.**
- **POST 200:** profil diperbarui. Konfirmasi "yakin update?" dilakukan di tampilan.

## 5. Publik: katalog dan info

### GET `/katalog/tipe`
Daftar tipe yang tersedia pada tanggal yang dipilih.

| Query | Aturan |
|---|---|
| `tgl_mulai` | wajib, paling cepat besok, paling jauh 30 hari dari hari ini |
| `durasi` | wajib, 1 sampai 30 |
| `kursi` | opsional, angka (cocok persis) atau `any` untuk semua |
| `transmisi` | opsional: `manual`, `matic`, atau `any` untuk semua. Tipe tampil bila punya minimal satu unit tersedia dengan transmisi itu. |

**200:**
```json
{"data": [{
  "id": 1, "nama": "Brio", "merk": "Honda", "kursi": 5, "bahan_bakar": "bensin",
  "tarif": {"dengan_sopir": 650000, "lepas_kunci_manual": 300000, "lepas_kunci_matic": 350000},
  "foto": ["https://.../brio-1.jpg"], "rating_rata": 4.8, "sisa_unit": 2
}]}
```
Tipe dengan `sisa_unit = 0` tidak ditampilkan. Aturan menghitung `sisa_unit` ada di bagian 12.

### GET `/katalog/tipe/{id}`
Query yang sama (opsional). **200:** detail tipe, tarif, sampai 3 foto, spesifikasi, `sisa_unit`, dan `review` (hanya yang `tampil_di_katalog = true`: `rating`, `komentar`, `nama`, `tgl_review`).

### GET `/info`
**200:** `{"data": {"jam_operasional": "07.00-21.00", "alamat": "...", "telepon": "...", "email": "...", "batas_antar_gratis": "Tlogomas"}}`.

## 6. User: pemesanan

### POST `/pemesanan`
Membuat pesanan berstatus `menunggu_pembayaran` dan transaksi Midtrans.

| Field | Aturan |
|---|---|
| `id_tipe` | wajib |
| `jenis_sewa` | wajib, `dengan_sopir` atau `lepas_kunci` |
| `tgl_mulai` | wajib, paling cepat besok, paling jauh 30 hari |
| `durasi` | wajib, 1 sampai 30 |
| `metode_ambil` | wajib untuk `lepas_kunci`: `ambil_di_kantor` atau `diantar`; tidak dipakai untuk `dengan_sopir` |
| `jam_serah` | wajib. Lepas kunci: jam ambil di kantor atau jam antar, antara 07:00 dan 21:00. Dengan sopir: jam jemput, bebas |
| `lokasi_jemput` | wajib untuk `dengan_sopir`; untuk `lepas_kunci` wajib hanya jika `diantar` (alamat bebas), kosong jika `ambil_di_kantor` |
| `catatan` | opsional |
| `setuju_syarat` | wajib `true` (semua jenis sewa) |
| `transmisi` | wajib untuk `lepas_kunci` |
| `setuju_syarat_jaminan` | wajib `true` untuk `lepas_kunci` |
| `tujuan` | wajib untuk `dengan_sopir`, teks bebas (boleh lebih dari satu tujuan); diperiksa admin setelah pesanan Dikonfirmasi |

Urutan pemeriksaan (galat pertama yang kena dikembalikan):
1. Validasi 422.
2. 409 `TAGIHAN_BELUM_LUNAS`: user masih punya tagihan tambahan belum lunas.
3. 409 `PESANAN_MENUNGGU_ADA`: user sudah punya satu pesanan `menunggu_pembayaran`.
4. 409 `UNIT_TIDAK_TERSEDIA`: "Mobil sudah dipesan pada tanggal tersebut".
5. 409 `SOPIR_TIDAK_TERSEDIA`: jumlah sopir tidak cukup (hanya jika pengaturan jumlah sopir terisi).

**201:**
```json
{"data": {
  "id": 15, "nomor": "PSN-0015", "status": "menunggu_pembayaran",
  "tgl_mulai": "2026-11-10", "durasi": 3, "tgl_kembali": "2026-11-12",
  "total_biaya": 900000, "batas_pembayaran": "2026-11-02T10:55:00+07:00",
  "midtrans": {"snap_token": "...", "order_id": "PSN-0015-1"}
}}
```
Pemeriksaan ketersediaan dan pembuatan pesanan berjalan dalam satu transaksi database dengan kunci baris (lihat bagian 12). Unit ditahan sampai `batas_pembayaran` (1 jam).

### GET `/pemesanan`
Daftar pesanan milik user. Query: `tab` (`berlangsung` berisi `menunggu_pembayaran`, `dikonfirmasi`, `berjalan`, `pengajuan_pembatalan`; `riwayat` berisi `selesai`, `dibatalkan`), `page`, `per_page`.
**200:** daftar ringkas (`id`, `nomor`, `status`, `tipe`, `tgl_mulai`, `durasi`, `total_biaya`, `aksi_utama`).

### GET `/pemesanan/{id}`
**200:** detail milik user. Bentuk khusus user: `plat_nomor` hanya terisi saat status `berjalan` atau setelahnya, tanpa `catatan_admin` dan `log_aktivitas`.
```json
{"data": {
  "id": 15, "nomor": "PSN-0015", "status": "dikonfirmasi",
  "tipe": {"id": 1, "nama": "Brio"}, "jenis_sewa": "lepas_kunci", "transmisi": "manual",
  "tgl_mulai": "2026-11-10", "durasi": 3, "tgl_kembali": "2026-11-12", "jam_serah": "09:00",
  "metode_ambil": "diantar", "lokasi_jemput": "Jl. Veteran No. 5", "tujuan": null, "total_biaya": 900000, "plat_nomor": null,
  "pembayaran": {"metode": "qris", "status": "lunas", "order_id": "PSN-0015-1"},
  "tagihan_tambahan": null, "jaminan": null, "hasil_pemeriksaan": null,
  "aksi": {"bisa_bayar": false, "bisa_batalkan": false, "bisa_ajukan_pembatalan": true,
           "bisa_review": false, "bisa_unduh_nota": true, "bisa_perpanjang": false, "bisa_pesan_lagi": false}
}}
```
Objek `aksi` menentukan tombol di tampilan (tombol yang tidak boleh tidak ditampilkan). Nilainya dihitung dari status dan tanggal. `bisa_perpanjang` hanya `true` saat status `berjalan`; tombol Perpanjang sewa membuka Katalog dengan tanggal mulai sehari setelah `tgl_kembali`.

### POST `/pemesanan/{id}/pembayaran`
Mengembalikan `snap_token` baru untuk pesanan `menunggu_pembayaran` yang belum lewat `batas_pembayaran` (tombol Bayar sekarang).
- **200:** `{"data": {"snap_token": "...", "batas_pembayaran": "..."}}`. **409** `PEMBAYARAN_KEDALUWARSA` jika sudah lewat.

### POST `/pemesanan/{id}/batalkan`
Hanya status `menunggu_pembayaran` (belum ada uang). Body kosong (konfirmasi "yakin?" di tampilan).
- **200:** status `dibatalkan`, unit dilepas. **409** `STATUS_TIDAK_VALID` untuk status lain.

### POST `/pemesanan/{id}/ajukan-pembatalan`
Hanya status `dikonfirmasi` dan paling lambat H-1 sebelum `tgl_mulai`. Body: `alasan` (wajib). Untuk pembayaran virtual account juga wajib `bank`, `no_rekening`, dan `nama_rekening` (tujuan refund); untuk QRIS tidak perlu.
- **200:** status `pengajuan_pembatalan`, `status_sebelumnya` disimpan, tanggal tetap terblokir, dan pengajuan masuk ke daftar admin. Tidak ada WhatsApp: seluruhnya lewat web.
- **409** `LEWAT_BATAS_H1`: "Pesanan tidak bisa dibatalkan". **409** `STATUS_TIDAK_VALID`.

### GET `/pemesanan/{id}/nota`
PDF nota (nomor, rincian biaya, status). Hanya untuk status `dikonfirmasi` ke atas. **200** `application/pdf`; **409** jika belum dibayar.

### POST `/pemesanan/{id}/review`
Hanya status `selesai`, sekali per pesanan. Body: `rating` (1 sampai 5), `komentar` (opsional, maks 500).
- **201** berhasil. **409** `SUDAH_ADA_REVIEW`, **409** `STATUS_TIDAK_VALID`.

## 7. Webhook dan tugas terjadwal

### POST `/webhook/midtrans` (publik, tanpa token)
Menerima notifikasi pembayaran Midtrans. Wajib memeriksa `signature_key = sha512(order_id + status_code + gross_amount + ServerKey)`; jika tidak cocok, 403.

| `transaction_status` | Akibat |
|---|---|
| `settlement` atau `capture` | pembayaran `lunas`, pesanan `dikonfirmasi` (tanggal terblokir), catat metode, log "Pembayaran berhasil" oleh Sistem. Sisa unit dicek ulang di dalam database transaction. Jika pesanan sudah `dibatalkan` karena kedaluwarsa dan jatah tipe masih ada, pesanan dihidupkan kembali menjadi `dikonfirmasi`; jika jatah sudah terpakai, pembayaran dibatalkan otomatis dan direfund penuh (QRIS lewat Midtrans, virtual account ditandai untuk ditransfer admin), tanpa pesanan baru |
| `expire` | pembayaran `gagal`, pesanan `dibatalkan`, unit dilepas |
| `deny` atau `cancel` | pembayaran `gagal`, pesanan tetap `menunggu_pembayaran` sampai kedaluwarsa |
| `pending` | tidak ada perubahan |

Webhook bersifat idempoten (notifikasi yang sama dua kali tidak mengubah apa pun). Respons selalu **200** setelah diproses.

### Tugas terjadwal (scheduler, tiap menit)
Pesanan `menunggu_pembayaran` yang lewat `batas_pembayaran`: pembayaran `gagal`, pesanan `dibatalkan`, unit dilepas, log dari Sistem. Ini cadangan jika notifikasi `expire` terlambat.

## 8. Admin: kelola mobil

### Tipe: `/admin/tipe`
- **GET** `/admin/tipe` (daftar dengan `jumlah_unit`), **GET** `/admin/tipe/{id}`.
- **POST** `/admin/tipe` (tambah) dan **POST** `/admin/tipe/{id}` (ubah; tidak ada `PUT`), keduanya `multipart/form-data`:

| Field | Aturan |
|---|---|
| `nama`, `merk` | wajib. Dirapikan otomatis: spasi ditrim dan digabung, huruf kapital di awal tiap kata (`tOYOta` menjadi `Toyota`); singkatan 1 sampai 3 huruf yang kapital semua dibiarkan (`BMW`, `CR-V`). Kombinasi nama + merk **unik tanpa peka huruf besar kecil**: bila sudah ada di tipe aktif, **422** `TIPE_SUDAH_ADA` (`errors.nama`); bila sama dengan tipe di tempat sampah, **422** `TIPE_ADA_DI_SAMPAH` (lihat "Tempat sampah" di bawah); tipe itu sendiri tidak dianggap duplikat saat ubah |
| `kursi` | wajib, bilangan bulat |
| `bahan_bakar` | wajib (`bensin`, `diesel`) |
| `harga_sopir_per_hari`, `harga_lepas_kunci_manual`, `harga_lepas_kunci_matic` | minimal satu terisi |
| `foto[]` | maksimal 3 berkas, jpg atau png, maks 2 MB |

- **DELETE** `/admin/tipe/{id}`: **soft delete** (hanya mengisi `deleted_at`; foto, berkas foto, dan tarif tetap ada) dan **204**. **409** `TIPE_MASIH_DIPAKAI` jika masih punya unit aktif atau pemesanan ("hapus unit tipe ini lebih dulu"). Konfirmasi "yakin hapus?" di tampilan. Tipe terhapus hilang dari katalog, daftar admin, dan ketersediaan, dan dijawab 404 di endpoint tipe; pulihkan lewat bagian "Tempat sampah". **DELETE** `/admin/tipe/{id}/foto/{id_foto}` (satu foto) tetap **dihapus permanen** bersama berkasnya: foto yang dibuang admin satu per satu sengaja tidak dianggap data yang perlu dipulihkan.
- Perubahan tarif hanya berlaku untuk pesanan baru. Pesanan lama menyimpan `total_biaya` sendiri.

### Unit: `/admin/unit`
- **GET** `/admin/unit` (query `id_tipe`, `status`), **GET** `/admin/unit/{id}` (termasuk detail kendaraan).
- **POST** `/admin/unit` dan **PUT** `/admin/unit/{id}`:

| Field | Aturan |
|---|---|
| `id_tipe`, `tahun`, `warna`, `transmisi` | wajib |
| `plat_nomor` | wajib, unik (422 "plat nomor sudah terdaftar"); bila sama dengan unit di tempat sampah, **422** `UNIT_ADA_DI_SAMPAH` |
| `km_terakhir` | bilangan bulat |
| `detail.nama_pemilik`, `detail.status_kepemilikan` | `milik_perusahaan` atau `titipan` |
| `detail.no_rangka`, `detail.no_mesin`, `detail.no_bpkb`, `detail.masa_berlaku_stnk`, `detail.catatan` | `no_rangka` dan `no_mesin` wajib; `no_bpkb`, `masa_berlaku_stnk`, `catatan` opsional. Ketiga nomor unik; bentrok dengan unit aktif 422 per kolom, dengan unit di tempat sampah 422 `UNIT_ADA_DI_SAMPAH` |

  Unit baru berstatus `tersedia`.
- **DELETE** `/admin/unit/{id}`: **soft delete** (hanya mengisi `deleted_at`; detail unit tetap ada) dan **204**. Unit yang punya riwayat pemesanan, serah terima, atau servis **boleh** dihapus. **409** hanya bila unit sedang disewa (`UNIT_SEDANG_DISEWA`, ada pesanan berjalan), sedang dalam servis (`UNIT_DALAM_SERVIS`), atau `PESANAN_TERDAMPAK` (kirim ulang dengan `konfirmasi: true`). Unit terhapus hilang dari daftar admin, katalog, ketersediaan, pemilihan unit serah terima, dan servis (404), tetapi riwayat pesanan, nota, dan laporan tetap menampilkan plat dan datanya. Pulihkan lewat bagian "Tempat sampah".
- **PATCH** `/admin/unit/{id}/status`: body `status` (`tidak_tersedia` atau `tersedia`), `konfirmasi` (opsional).
  - **409** `UNIT_SEDANG_DISEWA` atau `UNIT_DALAM_SERVIS`.
  - **409** `PESANAN_TERDAMPAK` jika menandai tidak tersedia membuat unit tipe itu tidak cukup untuk pesanan `dikonfirmasi`. Respons memuat `pesanan_terdampak` (daftar `id`, `nama`, `tgl_mulai`, `tgl_kembali`). Kirim ulang dengan `konfirmasi: true` untuk tetap melanjutkan (peringatan, bukan larangan).
  - **200** berhasil.


### Tempat sampah: `/admin/sampah`
Hapus tipe dan unit adalah **soft delete** (data bisa dipulihkan). Semua endpoint di bagian ini hanya untuk admin, dan hak aksesnya sama dengan endpoint admin lain.

- **GET** `/admin/sampah`: **200** `{"data": {"hari_sampah": 30, "tipe": [...], "unit": [...]}}`. Tiap tipe: `id`, `nama`, `merk`, `kursi`, `bahan_bakar`, `jumlah_foto`, `jumlah_unit_di_sampah`, `dihapus_pada`, `sisa_hari`. Tiap unit: `id`, `plat_nomor`, `tahun`, `warna`, `transmisi`, `tipe` (`id`, `nama`, `terhapus`), `punya_riwayat`, `dihapus_pada`, `sisa_hari`. `sisa_hari` = hari sebelum dibuang permanen (0 bila sudah lewat batas dan menunggu pembersihan berikutnya); **`null` untuk unit berriwayat** (`punya_riwayat: true`, pernah dipakai pesanan, serah terima, atau servis), karena unit itu tidak pernah dibuang permanen. `tipe.terhapus: true` berarti tipe unit itu juga di tempat sampah.
- **POST** `/admin/tipe/{id}/pulihkan`: **200** (isi sama dengan detail tipe). **409** `TIDAK_DI_SAMPAH` bila tipe masih aktif; **404** bila tidak ada.
- **POST** `/admin/unit/{id}/pulihkan`: **200** (isi sama dengan detail unit). Hanya bila tipenya aktif; bila tipenya di tempat sampah: **409** `UNIT_TIPE_DI_SAMPAH` (`detail` memuat `id` dan `nama` tipe), pulihkan tipe dulu. **409** `TIDAK_DI_SAMPAH` bila unit masih aktif.
- **DELETE** `/admin/sampah/tipe/{id}` dan `/admin/sampah/unit/{id}`: **hapus permanen satu data**, tidak bisa dibatalkan. Wajib `konfirmasi=true` (query atau body); tanpa itu **409** `KONFIRMASI_DIPERLUKAN` (`detail` memuat gambaran yang akan terhapus) dan tidak ada yang berubah. Hanya untuk data yang sudah di tempat sampah: data aktif **409** `TIDAK_DI_SAMPAH`, tidak ada sama sekali **404**. Tipe: foto (baris dan berkas) ikut terhapus, dan unit milik tipe itu yang juga di tempat sampah ikut dibuang permanen (**200** `{"data": {"id", "unit_ikut_dihapus", "foto_dihapus"}}`). Unit: detail ikut terhapus (**200** `{"data": {"id"}}`). Unit **berriwayat** ditolak **409** `UNIT_PUNYA_RIWAYAT` (dicek sebelum konfirmasi; `detail`: `jenis`, `id`, `plat_nomor`, `punya_riwayat: true`): sarannya pulihkan unit itu.
- Pulihkan dan hapus permanen dicatat di log aktivitas.
- **Pembuangan otomatis:** perintah `php artisan sampah:bersihkan` (terjadwal harian pukul 02:00) membuang permanen tipe dan unit **tanpa riwayat** yang di tempat sampah lebih dari `config('rental.hari_sampah')` hari (bawaan 30, bisa diubah lewat `HARI_SAMPAH` di `.env`), beserta berkas foto dan detailnya. Hanya menyentuh baris ber-`deleted_at`; berkas yang sudah hilang atau satu data bermasalah tidak menghentikan yang lain. Unit berriwayat selalu dilewati (tidak pernah dibuang otomatis), dan tipe yang masih punya unit (termasuk unit berriwayat atau yang belum kedaluwarsa) dilewati.

**Keunikan dan tempat sampah.** Nama + merk tipe, plat, `no_rangka`, `no_mesin`, dan `no_bpkb` tetap unik di database, termasuk terhadap data di tempat sampah. Membuat atau mengubah data yang bentrok dengan data di tempat sampah dijawab **422** dengan kode khusus dan data lama di `detail`:

```json
{
  "message": "Plat nomor, No rangka sama dengan unit N 1234 AB yang ada di tempat sampah. Pulihkan unit itu, atau hapus permanen dulu lalu buat ulang.",
  "code": "UNIT_ADA_DI_SAMPAH",
  "errors": {"plat_nomor": ["..."]},
  "detail": {
    "jenis": "unit",
    "id": 12,
    "plat_nomor": "N 1234 AB",
    "kolom_bentrok": ["plat_nomor", "no_rangka"],
    "punya_riwayat": false,
    "dihapus_pada": "2026-11-20T09:10:00+07:00",
    "sisa_hari": 28
  }
}
```

`TIPE_ADA_DI_SAMPAH` serupa, dengan `detail`: `jenis: "tipe"`, `id`, `nama`, `merk`, `kolom_bentrok: ["nama", "merk"]`, `dihapus_pada`, `sisa_hari`. Bila beberapa unit di tempat sampah bentrok sekaligus, yang dilaporkan unit dengan `id` terkecil. Untuk unit, `detail.punya_riwayat: true` (dengan `sisa_hari: null`) berarti unit lama itu berriwayat dan tidak bisa dihapus permanen: tampilkan pop-up hanya dengan pilihan **pulihkan** (tanpa tombol hapus permanen). Bentrok dengan data **aktif** tetap memakai kode lama (`TIPE_SUDAH_ADA`, atau 422 per kolom untuk unit).

**Alur pop-up tiga pilihan** (tampilan):
1. `POST /admin/tipe` atau `POST /admin/unit` (juga `POST /admin/tipe/{id}` dan `PUT /admin/unit/{id}`) dijawab 422 `TIPE_ADA_DI_SAMPAH` atau `UNIT_ADA_DI_SAMPAH`. Tampilkan pop-up dari `detail` (nama atau plat, tanggal dihapus, sisa hari) dengan tiga pilihan.
2. **Pulihkan:** `POST /admin/tipe/{detail.id}/pulihkan` (atau `/admin/unit/{detail.id}/pulihkan`). Bila 409 `UNIT_TIPE_DI_SAMPAH`, pulihkan tipenya dulu. Data lama kembali aktif; permintaan buat baru tidak perlu diulang.
3. **Hapus permanen lalu buat baru:** `DELETE /admin/sampah/tipe/{detail.id}?konfirmasi=true` (atau `/unit/`), lalu kirim ulang permintaan buat semula (sekarang berhasil 201). Tampilkan peringatan "tidak bisa dibatalkan" sebelum memanggil.
4. **Batal:** tidak ada panggilan.

## 9. Admin: pemesanan

### GET `/admin/pemesanan`
Query: `status`, `q` (nomor atau nama pelanggan), `page`, `per_page`. **200:** daftar ringkas (`id`, `nomor`, `tgl_pesan`, `pelanggan.nama`, `tipe`, `plat_nomor`, `tgl_mulai`, `status`). Tidak ada tombol aksi di daftar.

### GET `/admin/pemesanan/{id}`
Detail lengkap: semua field detail user, ditambah `pelanggan` (nama, no_hp, email, provinsi, kota, no_identitas), `serah_terima`, `jaminan`, `pengembalian`, `review`, `catatan_admin`, dan `log_aktivitas` (`waktu`, `aksi`, `oleh`: nama admin atau "Sistem" atau "Pelanggan"). Objek `aksi` berisi `bisa_serahkan`, `bisa_selesaikan`, `bisa_setujui`, `bisa_tolak`, `bisa_refund_selesai`, `bisa_tandai_lunas`, `bisa_kembalikan_jaminan` (tidak ada pengingat dan tidak ada tombol pembatalan oleh admin). Detail juga memuat `refund`, `refund_menunggu[]`, `alasan_pengajuan`, `alasan_pembatalan`, dan `status_sebelumnya`.

### PUT `/admin/pemesanan/{id}/catatan`
Body: `catatan_admin`. **200.**

### GET `/admin/pemesanan/{id}/unit-tersedia`
Untuk halaman Serahkan unit. **200:**
```json
{"data": {"unit": [{"id": 3, "plat_nomor": "N 1234 AB", "warna": "Putih", "km_terakhir": 45800}],
          "jumlah": 1}}
```
`unit` adalah unit tipe yang sama yang bebas pada tanggal sewa (disaring sistem). Karena jatah dijamin database transaction, daftar ini tidak pernah kosong untuk pesanan Dikonfirmasi yang sudah dibayar; jika kosong, itu kesalahan admin (mis. menandai unit tidak tersedia atau servis) dan tampilan menampilkan galat.

### POST `/admin/pemesanan/{id}/serah-terima`
Status harus `dikonfirmasi`. **Hanya `multipart/form-data`**; `application/json` dijawab **415** `FORMAT_TIDAK_DIDUKUNG`.

| Field | Aturan |
|---|---|
| `id_kendaraan` | wajib, harus ada di daftar `unit` dari endpoint di atas |
| `catatan_kondisi` | opsional |
| `foto_kondisi[]` | opsional (gambar, maks 5 MB per berkas, maks 10) |
| **Khusus `lepas_kunci`** | |
| `jaminan[dokumen_tambahan]` | wajib, jenis dokumen: `ktm`, `kk`, `npwp`, `bpjs`, `paspor` (butir c) |
| `foto_jaminan[ktp_asli]`, `foto_jaminan[sim_c_asli]` | **berkas foto wajib** (butir a dan b) |
| `foto_jaminan[dokumen_tambahan]` | berkas foto wajib (butir c) |
| `foto_jaminan[motor_stnk]` | berkas foto wajib jika domisili pesanan `malang_raya` (butir d) |
| `foto_jaminan[booking_transportasi]`, `foto_jaminan[booking_hotel]` | berkas foto wajib jika domisili pesanan `luar_malang_raya` (butir e) |
| `domisili_disesuaikan` | opsional (`malang_raya` atau `luar_malang_raya`); diisi jika KTP asli berbeda dengan domisili pesanan, menentukan foto yang wajib, dicatat di log aktivitas |
| `no_identitas` | wajib |
| `odometer_awal` | wajib |
| `bbm_awal` | wajib |

Foto jaminan: jpg atau png, maks 2 MB per berkas, disimpan di penyimpanan privat (bukan publik). Foto yang kurang menolak serah terima dengan **422** per item (mis. `foto_jaminan.ktp_asli`: "Foto KTP asli wajib diunggah") dan status pesanan tidak berubah. Untuk `dengan_sopir` tidak ada jaminan dan tidak ada foto jaminan.

- **200:** status `berjalan`, unit dianggap disewa, `jaminan.status = ditahan` (lepas kunci), tersimpan di `serah_terima`.
- **409** `UNIT_SUDAH_DIPAKAI`, **409** `STATUS_TIDAK_VALID`, **422** validasi.

### POST `/admin/pemesanan/{id}/pengembalian/preview`
Menghitung ringkasan biaya tanpa menyimpan (untuk ringkasan yang berubah langsung saat form diisi). Body sama dengan endpoint pengembalian. **200:** objek `ringkasan` (lihat di bawah).

### POST `/admin/pemesanan/{id}/pengembalian`
Menyelesaikan pesanan. Status harus `berjalan`. **Hanya `multipart/form-data`** (JSON dijawab **415** `FORMAT_TIDAK_DIDUKUNG`); field diratakan per bagian dan per jenis. Preview (`.../pengembalian/preview`) memakai body yang sama dan format yang sama, tanpa menuntut foto dan persetujuan penyewa.

| Field | Aturan |
|---|---|
| `tgl_kembali`, `jam_kembali` | wajib (tanggal dan jam aktual) |
| `kondisi[<bagian>]` | wajib untuk ke-8 bagian (`depan`, `belakang`, `sisi_kiri`, `sisi_kanan`, `atap`, `kaca_lampu`, `ban_velg`, `interior`); nilai `baik`, `lecet`, `penyok`, `retak`, `rusak` |
| `biaya[<bagian>]` | bulat; wajib jika `kondisi[<bagian>]` bukan `baik`, diabaikan jika `baik` |
| `keterangan[<bagian>]` | opsional |
| `foto[<bagian>]` | satu foto per bagian; wajib jika `kondisi[<bagian>]` bukan `baik`; opsional untuk bagian baik. Disimpan privat dan terkait ke bagiannya, sehingga terlihat bagian mana yang perlu diservis |
| `biaya_tambahan[<jenis>]` | opsional, nominal per jenis: `cuci`, `kelengkapan_hilang`, `biaya_antar`, `lainnya` (kosong atau 0 = tidak ada; satu isian per jenis). Tol dan parkir tidak dicatat: pada sewa dengan sopir dibayar langsung oleh penyewa saat perjalanan |
| `keterangan_tambahan[<jenis>]` | opsional |
| `disetujui_penyewa` | wajib `true` |
| `catatan_admin` | opsional |
| `status_pembayaran_tagihan` | `belum_dibayar`, `tunai`, atau `transfer` |
| `unit_perlu_servis` | boolean; jika `true`, wajib `servis[tingkat]` dan `servis[keperluan]` |
| **Khusus `lepas_kunci`** | |
| `odometer_akhir` | wajib, tidak boleh lebih kecil dari `odometer_awal` |
| `bbm_akhir`, `biaya_kekurangan_bbm`, `kebersihan` | wajib |

**200:**
```json
{"data": {"status": "selesai",
  "ringkasan": {"biaya_sewa": 900000, "hari_terlambat": 1, "denda_keterlambatan": 300000,
                "kekurangan_bbm": 30000, "biaya_kerusakan": 200000, "biaya_lain": 0,
                "tagihan_tambahan": 530000, "total_akhir": 1430000},
  "tagihan_status": "belum", "jaminan_status": "ditahan", "unit_status": "tersedia"}}
```
Akibat: status `selesai`, kilometer unit diperbarui, `tagihan_tambahan` tercatat sebagai pembayaran berjenis `tambahan`. Unit menjadi `tersedia`, atau `dalam_perawatan` beserta catatan servis (asal pesanan) jika `unit_perlu_servis`. Jaminan (lepas kunci) **otomatis** `dikembalikan` bila tagihan tambahan 0 atau tagihan langsung lunas (`status_pembayaran_tagihan` `tunai` atau `transfer`), karena pegawai yang menerima mobil memegang jaminan. Bila tagihan belum lunas, jaminan `ditahan` dan otomatis `dikembalikan` begitu tagihan itu lunas lewat `POST .../tagihan/lunas`. Dengan sopir tidak punya jaminan (`jaminan_status` `null`).
- **409** `STATUS_TIDAK_VALID`, **422** validasi (mis. odometer akhir lebih kecil, foto tidak ada).

### POST `/admin/pemesanan/{id}/setujui-pembatalan`
Status harus `pengajuan_pembatalan`. Konfirmasi "yakin?" di tampilan.
- **200:** status `dibatalkan`, unit dilepas (bisa dipesan lagi), pembayaran `dikembalikan`, dana kembali penuh. `refund.cara`: `midtrans` untuk QRIS (sistem memanggil refund Midtrans), `transfer_admin` untuk virtual account (admin mentransfer ke rekening yang diisi pelanggan di luar sistem, lalu menandai refund selesai).

### POST `/admin/pemesanan/{id}/refund-selesai`
Untuk semua pembayaran sewa `lunas` dengan `refund_cara = transfer_admin` dan `refund_selesai = false`, apa pun status pesanannya (pesanan dibatalkan, atau kelebihan bayar pada pesanan yang tetap aktif). Admin menekan ini setelah mentransfer di luar sistem. Bila satu pesanan punya lebih dari satu refund menunggu, kirim `id_pembayaran` (422 bila tidak).
- **200:** `refund_selesai = true` dan dicatat di log. **409** jika tidak ada refund yang menunggu.

### POST `/admin/pemesanan/{id}/tolak-pembatalan`
**200:** status kembali ke `status_sebelumnya`.

### POST `/admin/pemesanan/{id}/tagihan/lunas`
Status `selesai` dan tagihan `belum`. **200:** `tagihan_status` `lunas` dan `jaminan_status` (`dikembalikan` otomatis bila pesanan punya jaminan, `null` bila tidak). **409** jika tidak ada tagihan.

### POST `/admin/pemesanan/{id}/jaminan/kembalikan`
Status `selesai`, jenis `lepas_kunci`, jaminan `ditahan`. Dengan pengembalian otomatis, jalur manual ini hanya perlu untuk data lama yang jaminannya masih ditahan walau tagihan sudah lunas.
- **200:** jaminan `dikembalikan`. **409** `TAGIHAN_BELUM_LUNAS`: jaminan ditahan sampai tagihan lunas.

### PATCH `/admin/review/{id}`
Body: `tampil_di_katalog` (boolean). **200.** Dicatat di log.

## 10. Admin: servis

### GET `/admin/servis`
**200:** `ringkasan` (`unit_dalam_servis`, `unit_tersedia`, `total_biaya_bengkel`, `total_ditagih`, `total_ditanggung_perusahaan` = jumlah `biaya_ditanggung_perusahaan` tiap servis selesai). Tiap servis memuat `biaya_ditanggung_perusahaan` (`null` bila belum selesai atau dibatalkan) dan `biaya_bengkel` (`null` bila belum selesai atau dibatalkan; biaya hanya diisi saat servis diselesaikan, tidak ada estimasi dan `estimasi_biaya` yang dikirim diabaikan), `sedang_servis[]`, `riwayat[]`. Servis dari pengembalian memuat `asal_pesanan`.

### GET `/admin/servis/status-unit`
Pengingat berkala per unit. **200:** daftar `unit`, `km_terakhir`, `servis_terakhir`, `km_sejak_servis`, `status` (`aman`, `segera`, `perlu_servis`), `sisa_km`. Aturan hitung ada di bagian 12.

### POST `/admin/servis`
| Field | Aturan |
|---|---|
| `id_kendaraan`, `jenis_servis`, `tingkat_servis`, `tgl_perawatan`, `bengkel` | wajib |
| `km_saat_ini` | opsional; tidak boleh lebih kecil dari `km_terakhir` |
| `catatan` | opsional |

- **201:** servis `berlangsung`, unit `dalam_perawatan` (hilang dari katalog), `km_terakhir` diperbarui.
- **409** `UNIT_SEDANG_DISEWA`: "unit sedang disewa, perawatan ditunda". **409** `UNIT_SUDAH_DALAM_SERVIS`.
- **409** `PESANAN_TERDAMPAK` (seperti PATCH status unit): kirim ulang dengan `konfirmasi: true` untuk tetap mencatat.
- **422** `km_saat_ini` lebih kecil dari sebelumnya.

### POST `/admin/servis/{id}/selesai`
Body `multipart/form-data` (seperti `POST /admin/servis`; tidak ada field file): `komponen[]` (ulangi field untuk tiap komponen, minimal satu, terbaca sebagai array string), `biaya_bengkel` (wajib, bilangan bulat >= 0), `km_saat_ini` (wajib, tidak boleh lebih kecil).
```
komponen[]: Oli mesin dan filter
komponen[]: Rem
biaya_bengkel: 850000
km_saat_ini: 46100
```
`biaya_ditagih_ke_penyewa` bukan input dan bila dikirim diabaikan (tanpa 422). Nilainya dihitung saat selesai dan disimpan: bila servis berasal dari pengembalian (`asal_pesanan` terisi), sama dengan `biaya_kerusakan` pengembalian pesanan itu (jumlah biaya kondisi selain `baik`); servis manual, termasuk servis yang dibuat ulang setelah yang dari pengembalian dibatalkan, bernilai 0. Ini taksiran kerusakan dari pengembalian, bukan jumlah yang sudah dibayar penyewa (tagihan bisa masih `belum`). Satu pesanan hanya punya satu servis asal (unik).
- **200:** servis `selesai`, unit `tersedia`, `km_berikutnya = km_saat_ini + interval_km`, `tgl_berikutnya = tanggal selesai + 6 bulan`, `biaya_ditanggung_perusahaan = max(0, biaya_bengkel - biaya_ditagih)` (tidak pernah negatif), masuk riwayat.

### POST `/admin/servis/{id}/batal`
**200:** servis `dibatalkan`, unit `tersedia`.

### PUT `/admin/servis/interval`
Body: `interval_km` (> 0, bawaan 5000). **200:** status pengingat dihitung ulang.

## 11. Admin: dashboard dan laporan

### GET `/admin/dashboard`
**200:**
```json
{"data": {
  "hitungan": {"menunggu_pembayaran": 1, "dikonfirmasi": 2, "berjalan": 1, "pengajuan_pembatalan": 1,
               "unit_tersedia": 4, "unit_dalam_servis": 1},
  "tugas_admin": [{"id": 12, "jenis": "pengajuan_pembatalan", "keterangan": "..."}],
  "pengingat_servis": [{"id_kendaraan": 3, "plat_nomor": "N 1234 AB", "status": "segera"}]
}}
```
`tugas_admin` berisi pengajuan pembatalan, Serahkan unit (mulai hari ini atau besok), pengembalian jatuh tempo, tagihan belum dibayar, dan jaminan yang siap dikembalikan.

### GET `/admin/laporan`
| Query | Aturan |
|---|---|
| `periode` | `7` (bawaan), `30`, `bulan_ini`, `bulan_lalu`; dihitung dari `tgl_mulai` |
| `status` | opsional, satu nilai `pemesanan.status` |

**200:**
```json
{"data": {"periode": {"dari": "2026-10-23", "sampai": "2026-10-29"},
  "ringkasan": {"total_pendapatan": 3200000, "jumlah_transaksi": 6, "jumlah_dibatalkan": 1,
                "per_status": {"dikonfirmasi": 2, "berjalan": 1, "selesai": 2, "dibatalkan": 1}},
  "data": [{"tgl_mulai": "...", "nomor": "...", "pelanggan": "...", "unit": "...", "status": "...", "sewa": 900000, "tagihan_tambahan": 0}]}}
```
`total_pendapatan` hanya menjumlah pesanan berstatus `dikonfirmasi`, `berjalan`, dan `selesai` (biaya sewa + tagihan tambahan). Jika `data` kosong, tampilan menampilkan "tidak ada data pada periode ini" dan admin memilih periode lain (respons tetap 200).

### GET `/admin/laporan/pdf`
Query sama. **200** `application/pdf` (periode, tabel, jumlah transaksi, total pendapatan). **409** `DATA_KOSONG` jika tidak ada data (tombol PDF dinonaktifkan di tampilan).

## 12. Aturan hitung (server)

1. **Ketersediaan tipe pada rentang `[tgl_mulai, tgl_kembali]`:**
   - `kapasitas` = jumlah unit tipe dengan status `tersedia` (tidak `dalam_perawatan` atau `tidak_tersedia`).
   - `terpakai` = jumlah pesanan tipe yang rentangnya beririsan dengan status `menunggu_pembayaran` (yang belum lewat `batas_pembayaran`), `dikonfirmasi`, `berjalan`, atau `pengajuan_pembatalan`.
   - `sisa_unit = kapasitas - terpakai` (nilai minimum di antara semua hari pada rentang). Tersedia jika `sisa_unit >= 1`.
   - Untuk `dengan_sopir`, `jumlah_sopir` (pengaturan) juga dihitung dengan cara yang sama; pengaturan kosong berarti tidak dibatasi.
2. **Tanggal kembali:** `tgl_kembali = tgl_mulai + durasi - 1`.
3. **Biaya sewa:** `total_biaya = tarif_per_hari × durasi`; tarif dari tipe sesuai `jenis_sewa` dan `transmisi`.
4. **Denda keterlambatan:** `hari_terlambat = max(0, tanggal_kembali_aktual - tgl_kembali)` (selisih tanggal kalender; sampai 23.59 tanggal kembali tidak terlambat). `denda = hari_terlambat × tarif_per_hari` pesanan itu.
5. **Tagihan tambahan:** `denda + biaya_kekurangan_bbm + jumlah biaya kerusakan + jumlah biaya_tambahan`.
6. **Status servis unit:** dari servis `selesai` terakhir. `perlu_servis` jika `km_terakhir >= km_berikutnya` atau hari ini `>= tgl_berikutnya`. `segera` jika sisa km `<= 1000` atau sisa hari `<= 30`. `aman` selain itu. Unit yang belum pernah diservis memakai patokan saat diinput: `km_berikutnya = km_awal + interval_km` (`km_awal` = kilometer saat unit dibuat, default 0) dan `tgl_berikutnya = tanggal unit dibuat + 6 bulan`; `servis_terakhir` tetap `null` dan `km_sejak_servis` dihitung dari `km_awal`. Setelah ada servis selesai, patokan pindah ke servis terakhir.
7. **Batas pembatalan:** pengajuan hanya jika hari ini `<= tgl_mulai - 1 hari`.
8. **Peringatan pesanan terdampak:** `kapasitas` tipe setelah unit dinonaktifkan atau diservis dibandingkan dengan jumlah pesanan `dikonfirmasi` (belum punya plat) yang beririsan pada tanggal yang sama; jika kurang, kembalikan `PESANAN_TERDAMPAK`.

## 13. Catatan implementasi

- **Transaksi dan kunci:** `POST /pemesanan` dan `POST /admin/pemesanan/{id}/serah-terima` memakai transaksi database dengan `lockForUpdate()` pada unit atau tipe, agar dua pemesan tidak mengambil unit terakhir yang sama. Serah terima memeriksa ulang ketersediaan.
- **Log aktivitas:** setiap aksi admin menulis ke `log_aktivitas` (`id_akun`, `aksi`, `objek`, `id_objek`, `waktu`). Aksi sistem (webhook, scheduler) memakai pelaku "Sistem".
- **Pembatasan data per peran:** gunakan API Resource terpisah untuk user dan admin. Resource user tidak memuat `catatan_admin`, `log_aktivitas`, `no_identitas`, atau detail internal kendaraan. Filter kepemilikan: `where id_pelanggan = user saat ini`.
- **Pembatasan laju:** login 5 per menit per IP dan 10 per jam per email; lupa kata sandi 5 per menit per IP dan 3 per jam per email; reset kata sandi 5 per menit per IP; registrasi 10 per jam per IP; kirim ulang verifikasi 3 per jam per email dan IP. Lewat batas dijawab 429 `TERLALU_BANYAK_PERCOBAAN`.
- **Berkas:** foto disimpan di penyimpanan privat untuk kondisi unit, dan publik untuk foto tipe.
- **Midtrans:** gunakan sandbox saat pengembangan. Aplikasi harus bisa menerima webhook (server publik atau tunnel) saat demo.
- **Status pembayaran dan refund:** refund QRIS dipicu lewat Midtrans saat admin menyetujui pembatalan. Refund virtual account ditandai `dikembalikan` oleh admin lewat `/refund-selesai` setelah ditransfer di luar sistem.
- **Database transaction:** `POST /pemesanan` dan pengecekan ulang di webhook berjalan dalam satu transaksi dengan `lockForUpdate()` pada tipe, sehingga siapa yang tiba lebih dulu mendapat jatah dan yang kedua ditolak tanpa tagihan.
- **Tugas terjadwal tambahan:** pesanan `dikonfirmasi` yang lewat tanggal kembali tanpa serah terima ditutup menjadi `dibatalkan` (alasan `tidak_diambil`), pembayaran tetap `lunas`, tanpa refund.

## 14. Pemetaan flowchart ke endpoint

| Halaman flowchart | Endpoint |
|---|---|
| admin&user-log&register | `/auth/register`, `/auth/verifikasi-email`, `/auth/kirim-ulang-verifikasi`, `/auth/login`, `/auth/lupa-password`, `/auth/reset-password` |
| User-Profile User | `/profil`, `/profil/password` |
| user-pesan mobil | `/katalog/tipe`, `/katalog/tipe/{id}`, `/pemesanan`, `/pemesanan/{id}/pembayaran`, `/webhook/midtrans`, `/pemesanan/{id}/nota` |
| usert-cek riwayat transaksi, user-Lihat Detail Pesanan | `/pemesanan`, `/pemesanan/{id}`, `/pemesanan/{id}/review` |
| user-Batalkan Pemesanan | `/pemesanan/{id}/batalkan`, `/pemesanan/{id}/ajukan-pembatalan` |
| admin-Update, Input, dan Hapus Data Kendaraan | `/admin/tipe`, `/admin/unit`, `/admin/unit/{id}/status`, `/admin/sampah`, `/admin/tipe/{id}/pulihkan`, `/admin/unit/{id}/pulihkan`, `/admin/sampah/tipe/{id}`, `/admin/sampah/unit/{id}` |
| admin-Lihat Detail Pesanan | `/admin/pemesanan`, `/admin/pemesanan/{id}`, `/admin/pemesanan/{id}/unit-tersedia`, `/serah-terima`, `/refund-selesai`, `/tagihan/*`, `/jaminan/kembalikan`, `/admin/review/{id}` |
| admin-pengembalian mobil dan biaya tambahan | `/admin/pemesanan/{id}/pengembalian/preview`, `/pengembalian` |
| admin-Ubah Status Pemesanan | `/admin/pemesanan/{id}/setujui-pembatalan`, `/tolak-pembatalan` |
| admin-Perawatan Mobil Berkala | `/admin/servis`, `/admin/servis/status-unit`, `/admin/servis/{id}/selesai`, `/batal`, `/admin/servis/interval` |
| admin-Laporan Transaksi | `/admin/laporan`, `/admin/laporan/pdf` |
| Dashboard admin | `/admin/dashboard` |

## 15. Kode galat bisnis

| Kode | Status | Arti |
|---|---|---|
| `KREDENSIAL_SALAH` | 401 | email atau kata sandi salah |
| `EMAIL_BELUM_TERVERIFIKASI` | 403 | akun user belum memverifikasi email |
| `TOKEN_TIDAK_VALID` | 422 | token verifikasi atau reset salah, kedaluwarsa, atau sudah dipakai |
| `AKUN_NONAKTIF` | 403 | akun admin nonaktif |
| `GANTI_PASSWORD_DULU` | 403 | admin masih memakai kata sandi sementara |
| `EMAIL_TIDAK_TERDAFTAR` | 404 | email kirim ulang verifikasi tidak ada |
| `EMAIL_SUDAH_TERVERIFIKASI` | 409 | kirim ulang verifikasi untuk akun yang sudah terverifikasi |
| `TAGIHAN_BELUM_LUNAS` | 409 | user masih punya tagihan tambahan belum lunas |
| `PESANAN_MENUNGGU_ADA` | 409 | sudah ada satu pesanan menunggu pembayaran |
| `UNIT_TIDAK_TERSEDIA` | 409 | tidak ada unit tipe itu pada tanggal tersebut |
| `SOPIR_TIDAK_TERSEDIA` | 409 | sopir tidak cukup pada tanggal itu |
| `PEMBAYARAN_KEDALUWARSA` | 409 | lewat batas 1 jam |
| `LEWAT_BATAS_H1` | 409 | pengajuan pembatalan lewat H-1 |
| `STATUS_TIDAK_VALID` | 409 | aksi tidak boleh pada status pesanan saat ini |
| `SUDAH_ADA_REVIEW` | 409 | review hanya sekali per pesanan |
| `TIPE_MASIH_DIPAKAI` | 409 | tipe masih punya unit atau pemesanan |
| `UNIT_PUNYA_PEMESANAN` | 409 | tipe unit tidak bisa diubah karena unit pernah dipakai pemesanan |
| `UNIT_SEDANG_DISEWA` | 409 | unit tidak bisa dihapus karena ada pesanan berjalan |
| `UNIT_DALAM_SERVIS` | 409 | unit tidak bisa dihapus karena sedang dalam servis |
| `UNIT_PUNYA_RIWAYAT` | 409 | hapus permanen unit berriwayat ditolak; pulihkan saja |
| `UNIT_SEDANG_DISEWA` | 409 | unit disewa, tidak bisa diubah atau diservis |
| `UNIT_DALAM_SERVIS` / `UNIT_SUDAH_DALAM_SERVIS` | 409 | unit sudah dalam servis |
| `UNIT_SUDAH_DIPAKAI` | 409 | unit yang dipilih sudah tidak bebas saat serah terima |
| `PESANAN_TERDAMPAK` | 409 | peringatan, kirim ulang dengan `konfirmasi: true` |
| `TAGIHAN_BELUM_LUNAS` (admin) | 409 | jaminan ditahan sampai tagihan lunas |
| `DATA_KOSONG` | 409 | laporan PDF tidak ada datanya |
