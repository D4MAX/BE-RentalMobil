# Spesifikasi API: Sistem Rental Mobil

## 1. Konvensi

- **Format:** JSON (`Content-Type: application/json`). Unggah berkas memakai `multipart/form-data`.
- **Autentikasi:** `Authorization: Bearer {token}` (Sanctum). Tiap token membawa peran `user` atau `admin`.
- **Penamaan:** snake_case, mengikuti nama kolom di ERD (mis. `plat_nomor`, `tgl_mulai`, `jam_jemput`).
- **Tanggal dan jam:** tanggal `YYYY-MM-DD`, jam `HH:mm`, waktu lengkap ISO 8601 zona Asia/Jakarta. Uang berupa bilangan bulat rupiah.
- **Bentuk respons sukses:** `{"data": ...}`. Daftar: `{"data": [...], "meta": {"page", "per_page", "total"}}` (bawaan 10 per halaman, parameter `page` dan `per_page`).
- **Bentuk respons galat:**
  ```json
  {
    "message": "Mobil sudah dipesan pada tanggal tersebut",
    "code": "UNIT_TIDAK_TERSEDIA",
    "errors": { "field": ["..."] }
  }
  ```
  `errors` hanya ada pada 422.
- **Kode status:** 200, 201, 204 (berhasil); 401 (belum login); 403 (peran atau akun tidak boleh); 404; 409 (bentrok aturan bisnis); 422 (validasi); 429 (terlalu banyak percobaan).
- **Zona waktu dan aturan hari:** batas pengembalian adalah tanggal kembali pukul 23.59.

## 2. Enum

| Nama                   | Nilai                                                                                                              |
| ---------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `pemesanan.status`     | `menunggu_pembayaran`, `dikonfirmasi`, `berjalan`, `selesai`, `pengajuan_pembatalan`, `dibatalkan`                 |
| `pembayaran.jenis`     | `sewa`, `tambahan`, `refund`                                                                                       |
| `pembayaran.status`    | `menunggu`, `lunas`, `gagal`, `dikembalikan`                                                                       |
| `kendaraan.status`     | `tersedia`, `dalam_perawatan`, `tidak_tersedia` (status "disewa" tidak disimpan, dihitung dari pesanan `berjalan`) |
| `perawatan.status`     | `berlangsung`, `selesai`, `dibatalkan`                                                                             |
| `jenis_sewa`           | `dengan_sopir`, `lepas_kunci`                                                                                      |
| `transmisi`            | `manual`, `matic`                                                                                                  |
| `status_kepemilikan`   | `milik_perusahaan`, `titipan`                                                                                      |
| `kondisi`              | `baik`, `lecet`, `penyok`, `retak`, `rusak`                                                                        |
| `bbm`                  | `penuh`, `tiga_perempat`, `setengah`, `seperempat`, `hampir_kosong`                                                |
| `kebersihan`           | `bersih`, `kotor_biasa`, `sangat_kotor`                                                                            |
| `bagian` (8)           | `depan`, `belakang`, `sisi_kiri`, `sisi_kanan`, `atap`, `kaca_lampu`, `ban_velg`, `interior`                       |
| `tingkat_servis`       | `ringan`, `sedang`, `perlu_bengkel`                                                                                |
| `metode_bayar_tagihan` | `belum_dibayar`, `tunai`, `transfer`                                                                               |

## 3. Matriks akses

| Kelompok                                               | Guest                   | User               | Admin |
| ------------------------------------------------------ | ----------------------- | ------------------ | ----- |
| `/auth/*` (register, login, lupa dan reset kata sandi) | Ya                      | -                  | -     |
| `/auth/logout`, `/auth/ganti-password`, `/me`          | -                       | Ya                 | Ya    |
| `/katalog/*`, `/info`                                  | Ya                      | Ya                 | Ya    |
| `/profil`, `/pemesanan/*`                              | -                       | Ya (milik sendiri) | -     |
| `/admin/*`                                             | -                       | -                  | Ya    |
| `/webhook/midtrans`                                    | Midtrans (tanda tangan) | -                  | -     |

## 4. Autentikasi dan akun

### POST `/auth/register` (guest)

Membuat akun user. Admin tidak dibuat lewat API (dibuat pengembang lewat database).

| Field      | Aturan                                        |
| ---------- | --------------------------------------------- |
| `nama`     | wajib, maks 100                               |
| `email`    | wajib, format email, unik                     |
| `no_hp`    | wajib, nomor WhatsApp Indonesia               |
| `domisili` | wajib                                         |
| `password` | wajib, minimal 6, ada `password_confirmation` |

- **201:** `{"data": {"id": 1, "nama": "...", "email": "..."}}` (tidak mengembalikan token, user lanjut login).
- **422:** email sudah dipakai (`errors.email`).

### POST `/auth/login`

Body: `email`, `password`.

- **200:** `{"data": {"token": "...", "user": {"id", "nama", "role"}, "wajib_ganti_password": false}}`. Untuk admin dengan kata sandi sementara `wajib_ganti_password` bernilai `true`, dan semua endpoint lain mengembalikan 403 `GANTI_PASSWORD_DULU` sampai kata sandi diganti.
- **401** `KREDENSIAL_SALAH`: email atau kata sandi salah.
- **403** `AKUN_NONAKTIF`: akun admin nonaktif (`akun.aktif = false`).
- **429:** lebih dari 5 percobaan per menit.

### POST `/auth/logout`

Mencabut token saat ini. **204.**

### POST `/auth/lupa-password`

Body: `email`. Mengirim tautan reset lewat email (SMTP), berlaku 1 jam.

- **200:** `{"message": "Tautan reset dikirim"}`.
- **404** `EMAIL_TIDAK_TERDAFTAR`.

### POST `/auth/reset-password`

Body: `email`, `token`, `password`, `password_confirmation`.

- **200** berhasil. **422** token kedaluwarsa atau tidak valid.

### POST `/auth/ganti-password` (user, admin)

Body: `password_lama`, `password`, `password_confirmation`.

- **200** berhasil dan mengakhiri status kata sandi sementara. **422** kata sandi lama salah atau konfirmasi tidak cocok.

### GET `/me`

**200:** `{"data": {"id", "nama", "email", "role", "no_hp", "domisili"}}`.

### GET `/profil` dan PUT `/profil` (user)

Field: `nama`, `no_hp`, `email` (unik), `alamat`, `domisili`, `foto` (berkas, maks 2 MB, jpg atau png).

- **PUT 200:** profil diperbarui. Konfirmasi "yakin update?" dilakukan di tampilan.

## 5. Publik: katalog dan info

### GET `/katalog/tipe`

Daftar tipe yang tersedia pada tanggal yang dipilih.

| Query       | Aturan                                                       |
| ----------- | ------------------------------------------------------------ |
| `tgl_mulai` | wajib, paling cepat besok, paling jauh 30 hari dari hari ini |
| `durasi`    | wajib, 1 sampai 30                                           |
| `kursi`     | opsional                                                     |
| `transmisi` | opsional (`manual` atau `matic`)                             |

**200:**

```json
{
  "data": [
    {
      "id": 1,
      "nama": "Brio",
      "merk": "Honda",
      "kursi": 5,
      "bahan_bakar": "bensin",
      "tarif": {
        "dengan_sopir": 650000,
        "lepas_kunci_manual": 300000,
        "lepas_kunci_matic": 350000
      },
      "foto": ["https://.../brio-1.jpg"],
      "rating_rata": 4.8,
      "sisa_unit": 2
    }
  ]
}
```

Tipe dengan `sisa_unit = 0` tidak ditampilkan. Aturan menghitung `sisa_unit` ada di bagian 12.

### GET `/katalog/tipe/{id}`

Query yang sama (opsional). **200:** detail tipe, tarif, sampai 3 foto, spesifikasi, `sisa_unit`, dan `review` (hanya yang `tampil_di_katalog = true`: `rating`, `komentar`, `nama`, `tgl_review`).

### GET `/info`

**200:** `{"data": {"jam_operasional": "07.00-21.00", "nomor_wa_admin": "...", "batas_antar_gratis": "Tlogomas"}}`.

## 6. User: pemesanan

### POST `/pemesanan`

Membuat pesanan berstatus `menunggu_pembayaran` dan transaksi Midtrans.

| Field                      | Aturan                                                          |
| -------------------------- | --------------------------------------------------------------- |
| `id_tipe`                  | wajib                                                           |
| `jenis_sewa`               | wajib, `dengan_sopir` atau `lepas_kunci`                        |
| `tgl_mulai`                | wajib, paling cepat besok, paling jauh 30 hari                  |
| `durasi`                   | wajib, 1 sampai 30                                              |
| `jam_jemput`               | wajib. Lepas kunci: antara 07:00 dan 21:00. Dengan sopir: bebas |
| `lokasi_jemput`            | wajib, alamat bebas                                             |
| `catatan`                  | opsional                                                        |
| `setuju_syarat`            | wajib `true` (semua jenis sewa)                                 |
| `transmisi`                | wajib untuk `lepas_kunci`                                       |
| `setuju_syarat_jaminan`    | wajib `true` untuk `lepas_kunci`                                |
| `tujuan`                   | wajib untuk `dengan_sopir`, teks (boleh lebih dari satu tujuan) |
| `semua_tujuan_malang_batu` | wajib untuk `dengan_sopir`, harus `true`                        |

Urutan pemeriksaan (galat pertama yang kena dikembalikan):

1. Validasi 422.
2. 422 `LUAR_WILAYAH`: `semua_tujuan_malang_batu` bernilai `false` ("hubungi admin lewat WhatsApp"), respons menyertakan `whatsapp_url`. Pesanan tidak dibuat.
3. 409 `TAGIHAN_BELUM_LUNAS`: user masih punya tagihan tambahan belum lunas.
4. 409 `PESANAN_MENUNGGU_ADA`: user sudah punya satu pesanan `menunggu_pembayaran`.
5. 409 `UNIT_TIDAK_TERSEDIA`: "Mobil sudah dipesan pada tanggal tersebut".
6. 409 `SOPIR_TIDAK_TERSEDIA`: jumlah sopir tidak cukup (hanya jika pengaturan jumlah sopir terisi).

**201:**

```json
{
  "data": {
    "id": 15,
    "nomor": "PSN-0015",
    "status": "menunggu_pembayaran",
    "tgl_mulai": "2026-11-10",
    "durasi": 3,
    "tgl_kembali": "2026-11-12",
    "total_biaya": 900000,
    "batas_pembayaran": "2026-11-02T10:55:00+07:00",
    "midtrans": { "snap_token": "...", "order_id": "PSN-0015-1" }
  }
}
```

Pemeriksaan ketersediaan dan pembuatan pesanan berjalan dalam satu transaksi database dengan kunci baris (lihat bagian 12). Unit ditahan sampai `batas_pembayaran` (1 jam).

### GET `/pemesanan`

Daftar pesanan milik user. Query: `tab` (`berlangsung` berisi `menunggu_pembayaran`, `dikonfirmasi`, `berjalan`, `pengajuan_pembatalan`; `riwayat` berisi `selesai`, `dibatalkan`), `page`, `per_page`.
**200:** daftar ringkas (`id`, `nomor`, `status`, `tipe`, `tgl_mulai`, `durasi`, `total_biaya`, `aksi_utama`).

### GET `/pemesanan/{id}`

**200:** detail milik user. Bentuk khusus user: `plat_nomor` hanya terisi saat status `berjalan` atau setelahnya, tanpa `catatan_admin` dan `log_aktivitas`.

```json
{
  "data": {
    "id": 15,
    "nomor": "PSN-0015",
    "status": "dikonfirmasi",
    "tipe": { "id": 1, "nama": "Brio" },
    "jenis_sewa": "lepas_kunci",
    "transmisi": "manual",
    "tgl_mulai": "2026-11-10",
    "durasi": 3,
    "tgl_kembali": "2026-11-12",
    "jam_jemput": "09:00",
    "lokasi_jemput": "Jl. Veteran No. 5",
    "tujuan": null,
    "total_biaya": 900000,
    "plat_nomor": null,
    "pembayaran": {
      "metode": "qris",
      "status": "lunas",
      "order_id": "PSN-0015-1"
    },
    "tagihan_tambahan": null,
    "jaminan": null,
    "hasil_pemeriksaan": null,
    "aksi": {
      "bisa_bayar": false,
      "bisa_batalkan": false,
      "bisa_ajukan_pembatalan": true,
      "bisa_review": false,
      "bisa_unduh_nota": true,
      "bisa_pesan_lagi": false
    }
  }
}
```

Objek `aksi` menentukan tombol di tampilan (tombol yang tidak boleh tidak ditampilkan). Nilainya dihitung dari status dan tanggal.

### POST `/pemesanan/{id}/pembayaran`

Mengembalikan `snap_token` baru untuk pesanan `menunggu_pembayaran` yang belum lewat `batas_pembayaran` (tombol Bayar sekarang).

- **200:** `{"data": {"snap_token": "...", "batas_pembayaran": "..."}}`. **409** `PEMBAYARAN_KEDALUWARSA` jika sudah lewat.

### POST `/pemesanan/{id}/batalkan`

Hanya status `menunggu_pembayaran` (belum ada uang). Body kosong (konfirmasi "yakin?" di tampilan).

- **200:** status `dibatalkan`, unit dilepas, tanpa WhatsApp. **409** `STATUS_TIDAK_VALID` untuk status lain.

### POST `/pemesanan/{id}/ajukan-pembatalan`

Hanya status `dikonfirmasi` dan paling lambat H-1 sebelum `tgl_mulai`. Body: `alasan` (wajib).

- **200:** status `pengajuan_pembatalan`, `status_sebelumnya` disimpan, tanggal tetap terblokir.
  ```json
  {
    "data": {
      "status": "pengajuan_pembatalan",
      "whatsapp_url": "https://wa.me/62812...?text=Halo%20Admin%2C%20saya%20mengajukan%20pembatalan%20pesanan%20PSN-0015..."
    }
  }
  ```
  Isi pesan: ID pesanan, nama, unit, tanggal mulai, total dibayar, metode pembayaran, status, alasan. Untuk pembayaran virtual account ditambah kalimat bahwa user akan mengirim data rekening.
- **409** `LEWAT_BATAS_H1`: "Pesanan tidak bisa dibatalkan". **409** `STATUS_TIDAK_VALID`.

### GET `/pemesanan/{id}/nota`

PDF nota (nomor, rincian biaya, status). Hanya untuk status `dikonfirmasi` ke atas. **200** `application/pdf`; **409** jika belum dibayar.

### POST `/pemesanan/{id}/review`

Hanya status `selesai`, sekali per pesanan. Body: `rating` (1 sampai 5), `komentar` (opsional, maks 500).

- **201** berhasil. **409** `SUDAH_ADA_REVIEW`, **409** `STATUS_TIDAK_VALID`.

## 7. Webhook dan tugas terjadwal

### POST `/webhook/midtrans` (publik, tanpa token)

Menerima notifikasi pembayaran Midtrans. Wajib memeriksa `signature_key = sha512(order_id + status_code + gross_amount + ServerKey)`; jika tidak cocok, 403.

| `transaction_status`        | Akibat                                                                                                              |
| --------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `settlement` atau `capture` | pembayaran `lunas`, pesanan `dikonfirmasi` (tanggal terblokir), catat metode, log "Pembayaran berhasil" oleh Sistem |
| `expire`                    | pembayaran `gagal`, pesanan `dibatalkan`, unit dilepas                                                              |
| `deny` atau `cancel`        | pembayaran `gagal`, pesanan tetap `menunggu_pembayaran` sampai kedaluwarsa                                          |
| `pending`                   | tidak ada perubahan                                                                                                 |

Webhook bersifat idempoten (notifikasi yang sama dua kali tidak mengubah apa pun). Respons selalu **200** setelah diproses.

### Tugas terjadwal (scheduler, tiap menit)

Pesanan `menunggu_pembayaran` yang lewat `batas_pembayaran`: pembayaran `gagal`, pesanan `dibatalkan`, unit dilepas, log dari Sistem. Ini cadangan jika notifikasi `expire` terlambat.

## 8. Admin: kelola mobil

### Tipe: `/admin/tipe`

- **GET** `/admin/tipe` (daftar dengan `jumlah_unit`), **GET** `/admin/tipe/{id}`.
- **POST** `/admin/tipe` dan **PUT** `/admin/tipe/{id}` (multipart):

| Field                                                                         | Aturan                                     |
| ----------------------------------------------------------------------------- | ------------------------------------------ |
| `nama`, `merk`                                                                | wajib                                      |
| `kursi`                                                                       | wajib, bilangan bulat                      |
| `bahan_bakar`                                                                 | wajib (`bensin`, `diesel`, `listrik`)      |
| `harga_sopir_per_hari`, `harga_lepas_kunci_manual`, `harga_lepas_kunci_matic` | minimal satu terisi                        |
| `foto[]`                                                                      | maksimal 3 berkas, jpg atau png, maks 2 MB |

- **DELETE** `/admin/tipe/{id}`: **409** `TIPE_MASIH_DIPAKAI` jika masih punya unit atau pemesanan ("hapus unit tipe ini lebih dulu"). Konfirmasi "yakin hapus?" di tampilan.
- Perubahan tarif hanya berlaku untuk pesanan baru. Pesanan lama menyimpan `total_biaya` sendiri.

### Unit: `/admin/unit`

- **GET** `/admin/unit` (query `id_tipe`, `status`), **GET** `/admin/unit/{id}` (termasuk detail kendaraan).
- **POST** `/admin/unit` dan **PUT** `/admin/unit/{id}`:

| Field                                                                                                 | Aturan                                         |
| ----------------------------------------------------------------------------------------------------- | ---------------------------------------------- |
| `id_tipe`, `tahun`, `warna`, `transmisi`                                                              | wajib                                          |
| `plat_nomor`                                                                                          | wajib, unik (422 "plat nomor sudah terdaftar") |
| `km_terakhir`                                                                                         | bilangan bulat                                 |
| `detail.nama_pemilik`, `detail.status_kepemilikan`                                                    | `milik_perusahaan` atau `titipan`              |
| `detail.no_rangka`, `detail.no_mesin`, `detail.no_bpkb`, `detail.masa_berlaku_stnk`, `detail.catatan` | opsional                                       |

Unit baru berstatus `tersedia`.

- **DELETE** `/admin/unit/{id}`: **409** `UNIT_PUNYA_PEMESANAN`.
- **PATCH** `/admin/unit/{id}/status`: body `status` (`tidak_tersedia` atau `tersedia`), `konfirmasi` (opsional).
  - **409** `UNIT_SEDANG_DISEWA` atau `UNIT_DALAM_SERVIS`.
  - **409** `PESANAN_TERDAMPAK` jika menandai tidak tersedia membuat unit tipe itu tidak cukup untuk pesanan `dikonfirmasi`. Respons memuat `pesanan_terdampak` (daftar `id`, `nama`, `tgl_mulai`, `tgl_kembali`). Kirim ulang dengan `konfirmasi: true` untuk tetap melanjutkan (peringatan, bukan larangan).
  - **200** berhasil.

## 9. Admin: pemesanan

### GET `/admin/pemesanan`

Query: `status`, `q` (nomor atau nama pelanggan), `page`, `per_page`. **200:** daftar ringkas (`id`, `nomor`, `tgl_pesan`, `pelanggan.nama`, `tipe`, `plat_nomor`, `tgl_mulai`, `status`). Tidak ada tombol aksi di daftar.

### GET `/admin/pemesanan/{id}`

Detail lengkap: semua field detail user, ditambah `pelanggan` (nama, no_hp, email, domisili, no_identitas), `serah_terima`, `jaminan`, `pengembalian`, `review`, `catatan_admin`, dan `log_aktivitas` (`waktu`, `aksi`, `oleh`: nama admin atau "Sistem" atau "Pelanggan"). Objek `aksi` berisi `bisa_serahkan`, `bisa_selesaikan`, `bisa_setujui`, `bisa_tolak`, `bisa_tandai_lunas`, `bisa_kirim_pengingat`, `bisa_kembalikan_jaminan`.

### PUT `/admin/pemesanan/{id}/catatan`

Body: `catatan_admin`. **200.**

### GET `/admin/pemesanan/{id}/unit-tersedia`

Untuk halaman Serahkan unit. **200:**

```json
{
  "data": {
    "unit": [
      {
        "id": 3,
        "plat_nomor": "N 1234 AB",
        "warna": "Putih",
        "km_terakhir": 45800
      }
    ],
    "pengganti": []
  }
}
```

`unit` adalah unit tipe yang sama yang bebas pada tanggal sewa (disaring sistem). `pengganti` hanya diisi jika `unit` kosong, berisi unit tipe setara atau lebih tinggi (tarif tidak lebih rendah).

### POST `/admin/pemesanan/{id}/serah-terima`

Status harus `dikonfirmasi`. Multipart.

| Field                                            | Aturan                                                                      |
| ------------------------------------------------ | --------------------------------------------------------------------------- |
| `id_kendaraan`                                   | wajib, harus ada di `unit` atau `pengganti` dari endpoint di atas           |
| `lokasi_antar_luar_tlogomas`                     | boolean; jika `true`, log mencatat bahwa biaya antar dibahas lewat WhatsApp |
| `catatan_kondisi`                                | opsional                                                                    |
| `foto_kondisi[]`                                 | opsional                                                                    |
| **Khusus `lepas_kunci`**                         |                                                                             |
| `jaminan.ktp_asli`, `jaminan.sim_c_asli`         | wajib `true`                                                                |
| `jaminan.jaminan_tambahan`, `jaminan.motor_stnk` | opsional                                                                    |
| `no_identitas`                                   | wajib                                                                       |
| `odometer_awal`                                  | wajib                                                                       |
| `bbm_awal`                                       | wajib                                                                       |

- **200:** status `berjalan`, unit dianggap disewa, `jaminan.status = ditahan` (lepas kunci), tersimpan di `serah_terima`. Jika unit dari tipe lain, log mencatat "unit pengganti, tarif tetap".
- **409** `UNIT_TIDAK_BEBAS`, **409** `STATUS_TIDAK_VALID`, **422** validasi.

### POST `/admin/pemesanan/{id}/batalkan-unit-tidak-tersedia`

Aksi opsional jika tidak ada plat yang bisa dipilih dan pelanggan sudah setuju lewat WhatsApp. Body: `konfirmasi: true`. Status harus `dikonfirmasi`.

- **200:** status `dibatalkan`, pembayaran `dikembalikan`, dana kembali penuh. Respons memuat `refund`: `{"metode": "qris", "cara": "midtrans"}` atau `{"metode": "va", "cara": "whatsapp"}`.

### POST `/admin/pemesanan/{id}/pengembalian/preview`

Menghitung ringkasan biaya tanpa menyimpan (untuk ringkasan yang berubah langsung saat form diisi). Body sama dengan endpoint pengembalian. **200:** objek `ringkasan` (lihat di bawah).

### POST `/admin/pemesanan/{id}/pengembalian`

Menyelesaikan pesanan. Status harus `berjalan`. Multipart.

| Field                                             | Aturan                                                                                                |
| ------------------------------------------------- | ----------------------------------------------------------------------------------------------------- |
| `tgl_kembali`, `jam_kembali`                      | wajib (tanggal dan jam aktual)                                                                        |
| `kondisi[]`                                       | wajib, 8 elemen: `bagian`, `kondisi`, `biaya` (bulat, wajib jika bukan `baik`), `keterangan`          |
| `foto[]`                                          | wajib jika ada `kondisi` selain `baik`                                                                |
| `biaya_tambahan[]`                                | opsional: `jenis` (`cuci`, `kelengkapan_hilang`, `tol`, `parkir`, `lainnya`), `keterangan`, `nominal` |
| `disetujui_penyewa`                               | wajib `true`                                                                                          |
| `catatan_admin`                                   | opsional                                                                                              |
| `status_pembayaran_tagihan`                       | `belum_dibayar`, `tunai`, atau `transfer`                                                             |
| `unit_perlu_servis`                               | boolean; jika `true`, wajib `servis.tingkat` dan `servis.keperluan`                                   |
| **Khusus `lepas_kunci`**                          |                                                                                                       |
| `odometer_akhir`                                  | wajib, tidak boleh lebih kecil dari `odometer_awal`                                                   |
| `bbm_akhir`, `biaya_kekurangan_bbm`, `kebersihan` | wajib                                                                                                 |
| `kembalikan_jaminan`                              | boolean; hanya boleh `true` jika tagihan tambahan 0                                                   |

**200:**

```json
{
  "data": {
    "status": "selesai",
    "ringkasan": {
      "biaya_sewa": 900000,
      "hari_terlambat": 1,
      "denda_keterlambatan": 300000,
      "kekurangan_bbm": 30000,
      "biaya_kerusakan": 200000,
      "biaya_lain": 0,
      "tagihan_tambahan": 530000,
      "total_akhir": 1430000
    },
    "tagihan_status": "belum",
    "jaminan_status": "ditahan",
    "unit_status": "tersedia"
  }
}
```

Akibat: status `selesai`, kilometer unit diperbarui, `tagihan_tambahan` tercatat sebagai pembayaran berjenis `tambahan`. Unit menjadi `tersedia`, atau `dalam_perawatan` beserta catatan servis (asal pesanan) jika `unit_perlu_servis`. Jaminan: `ditahan` jika ada tagihan atau `kembalikan_jaminan` tidak dikirim, `dikembalikan` jika tagihan 0 dan `kembalikan_jaminan` bernilai `true`.

- **409** `STATUS_TIDAK_VALID`, **422** validasi (mis. odometer akhir lebih kecil, foto tidak ada).

### POST `/admin/pemesanan/{id}/setujui-pembatalan`

Status harus `pengajuan_pembatalan`. Konfirmasi "yakin?" di tampilan.

- **200:** status `dibatalkan`, unit dilepas (bisa dipesan lagi), pembayaran `dikembalikan`, dana kembali penuh. `refund.cara`: `midtrans` untuk QRIS (sistem memanggil refund Midtrans), `whatsapp` untuk virtual account (admin mengurus di luar sistem).

### POST `/admin/pemesanan/{id}/tolak-pembatalan`

**200:** status kembali ke `status_sebelumnya`.

### POST `/admin/pemesanan/{id}/tagihan/lunas`

Status `selesai` dan tagihan `belum`. **200:** tagihan `lunas`. **409** jika tidak ada tagihan.

### POST `/admin/pemesanan/{id}/tagihan/pengingat`

**200:** `{"data": {"whatsapp_url": "https://wa.me/...?text=..."}}`. Dicatat di log.

### POST `/admin/pemesanan/{id}/jaminan/kembalikan`

Status `selesai`, jenis `lepas_kunci`, jaminan `ditahan`.

- **200:** jaminan `dikembalikan`. **409** `TAGIHAN_BELUM_LUNAS`: jaminan ditahan sampai tagihan lunas.

### PATCH `/admin/review/{id}`

Body: `tampil_di_katalog` (boolean). **200.** Dicatat di log.

## 10. Admin: servis

### GET `/admin/servis`

**200:** `ringkasan` (`unit_dalam_servis`, `unit_tersedia`, `total_biaya_bengkel`, `total_ditagih`), `sedang_servis[]`, `riwayat[]`. Servis dari pengembalian memuat `asal_pesanan`.

### GET `/admin/servis/status-unit`

Pengingat berkala per unit. **200:** daftar `unit`, `km_terakhir`, `servis_terakhir`, `km_sejak_servis`, `status` (`aman`, `segera`, `perlu_servis`, `belum_ada_data`), `sisa_km`. Aturan hitung ada di bagian 12.

### POST `/admin/servis`

| Field                                                                        | Aturan                                               |
| ---------------------------------------------------------------------------- | ---------------------------------------------------- |
| `id_kendaraan`, `jenis_servis`, `tingkat_servis`, `tgl_perawatan`, `bengkel` | wajib                                                |
| `estimasi_biaya`                                                             | opsional                                             |
| `km_saat_ini`                                                                | opsional; tidak boleh lebih kecil dari `km_terakhir` |
| `catatan`                                                                    | opsional                                             |

- **201:** servis `berlangsung`, unit `dalam_perawatan` (hilang dari katalog), `km_terakhir` diperbarui.
- **409** `UNIT_SEDANG_DISEWA`: "unit sedang disewa, perawatan ditunda". **409** `UNIT_SUDAH_DALAM_SERVIS`.
- **409** `PESANAN_TERDAMPAK` (seperti PATCH status unit): kirim ulang dengan `konfirmasi: true` untuk tetap mencatat.
- **422** `km_saat_ini` lebih kecil dari sebelumnya.

### POST `/admin/servis/{id}/selesai`

Body: `komponen[]` (minimal satu), `biaya_bengkel` (wajib), `biaya_ditagih_ke_penyewa` (opsional), `km_saat_ini` (wajib, tidak boleh lebih kecil).

- **200:** servis `selesai`, unit `tersedia`, `km_berikutnya = km_saat_ini + interval_km`, `tgl_berikutnya = tanggal selesai + 6 bulan`, `selisih_biaya = biaya_ditagih - biaya_bengkel`, masuk riwayat.

### POST `/admin/servis/{id}/batal`

**200:** servis `dibatalkan`, unit `tersedia`.

### PUT `/admin/servis/interval`

Body: `interval_km` (> 0, bawaan 5000). **200:** status pengingat dihitung ulang.

## 11. Admin: dashboard dan laporan

### GET `/admin/dashboard`

**200:**

```json
{
  "data": {
    "hitungan": {
      "menunggu_pembayaran": 1,
      "dikonfirmasi": 2,
      "berjalan": 1,
      "pengajuan_pembatalan": 1,
      "unit_tersedia": 4,
      "unit_dalam_servis": 1
    },
    "perlu_tindakan": [
      { "id": 12, "jenis": "pengajuan_pembatalan", "keterangan": "..." }
    ],
    "pengingat_servis": [
      { "id_kendaraan": 3, "plat_nomor": "N 1234 AB", "status": "segera" }
    ]
  }
}
```

`perlu_tindakan` berisi pengajuan pembatalan, Serahkan unit (mulai hari ini atau besok), pengembalian jatuh tempo, tagihan belum dibayar, dan jaminan yang siap dikembalikan.

### GET `/admin/laporan`

| Query     | Aturan                                                                   |
| --------- | ------------------------------------------------------------------------ |
| `periode` | `7` (bawaan), `30`, `bulan_ini`, `bulan_lalu`; dihitung dari `tgl_mulai` |
| `status`  | opsional, satu nilai `pemesanan.status`                                  |

**200:**

```json
{
  "data": {
    "periode": { "dari": "2026-10-23", "sampai": "2026-10-29" },
    "ringkasan": {
      "total_pendapatan": 3200000,
      "jumlah_transaksi": 6,
      "jumlah_dibatalkan": 1,
      "per_status": {
        "dikonfirmasi": 2,
        "berjalan": 1,
        "selesai": 2,
        "dibatalkan": 1
      }
    },
    "data": [
      {
        "tgl_mulai": "...",
        "nomor": "...",
        "pelanggan": "...",
        "unit": "...",
        "status": "...",
        "sewa": 900000,
        "tagihan_tambahan": 0
      }
    ]
  }
}
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
6. **Status servis unit:** dari servis `selesai` terakhir. `perlu_servis` jika `km_terakhir >= km_berikutnya` atau hari ini `>= tgl_berikutnya`. `segera` jika sisa km `<= 1000` atau sisa hari `<= 30`. `aman` selain itu. `belum_ada_data` jika belum ada servis selesai.
7. **Batas pembatalan:** pengajuan hanya jika hari ini `<= tgl_mulai - 1 hari`.
8. **Peringatan pesanan terdampak:** `kapasitas` tipe setelah unit dinonaktifkan atau diservis dibandingkan dengan jumlah pesanan `dikonfirmasi` (belum punya plat) yang beririsan pada tanggal yang sama; jika kurang, kembalikan `PESANAN_TERDAMPAK`.

## 13. Catatan implementasi

- **Transaksi dan kunci:** `POST /pemesanan` dan `POST /admin/pemesanan/{id}/serah-terima` memakai transaksi database dengan `lockForUpdate()` pada unit atau tipe, agar dua pemesan tidak mengambil unit terakhir yang sama. Serah terima memeriksa ulang ketersediaan.
- **Log aktivitas:** setiap aksi admin menulis ke `log_aktivitas` (`id_akun`, `aksi`, `objek`, `id_objek`, `waktu`). Aksi sistem (webhook, scheduler) memakai pelaku "Sistem".
- **Pembatasan data per peran:** gunakan API Resource terpisah untuk user dan admin. Resource user tidak memuat `catatan_admin`, `log_aktivitas`, `no_identitas`, atau detail internal kendaraan. Filter kepemilikan: `where id_pelanggan = user saat ini`.
- **Pembatasan laju:** login dan lupa kata sandi dibatasi 5 per menit per IP.
- **Berkas:** foto disimpan di penyimpanan privat untuk kondisi unit, dan publik untuk foto tipe.
- **Midtrans:** gunakan sandbox saat pengembangan. Aplikasi harus bisa menerima webhook (server publik atau tunnel) saat demo.
- **Status pembayaran dan refund:** refund QRIS dipicu lewat Midtrans saat admin menyetujui pembatalan. Refund virtual account hanya menandai pembayaran `dikembalikan` setelah admin mengurusnya lewat WhatsApp.

## 14. Pemetaan flowchart ke endpoint

| Halaman flowchart                                      | Endpoint                                                                                                                                                                                        |
| ------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| admin&user-log&register                                | `/auth/register`, `/auth/login`, `/auth/lupa-password`, `/auth/reset-password`, `/auth/ganti-password`                                                                                          |
| User-Profile User                                      | `/profil`, `/auth/ganti-password`                                                                                                                                                               |
| user-pesan mobil                                       | `/katalog/tipe`, `/katalog/tipe/{id}`, `/pemesanan`, `/pemesanan/{id}/pembayaran`, `/webhook/midtrans`, `/pemesanan/{id}/nota`                                                                  |
| usert-cek riwayat transaksi, user-Lihat Detail Pesanan | `/pemesanan`, `/pemesanan/{id}`, `/pemesanan/{id}/review`                                                                                                                                       |
| user-Batalkan Pemesanan                                | `/pemesanan/{id}/batalkan`, `/pemesanan/{id}/ajukan-pembatalan`                                                                                                                                 |
| admin-Update, Input, dan Hapus Data Kendaraan          | `/admin/tipe`, `/admin/unit`, `/admin/unit/{id}/status`                                                                                                                                         |
| admin-Lihat Detail Pesanan                             | `/admin/pemesanan`, `/admin/pemesanan/{id}`, `/admin/pemesanan/{id}/unit-tersedia`, `/serah-terima`, `/batalkan-unit-tidak-tersedia`, `/tagihan/*`, `/jaminan/kembalikan`, `/admin/review/{id}` |
| admin-pengembalian mobil dan biaya tambahan            | `/admin/pemesanan/{id}/pengembalian/preview`, `/pengembalian`                                                                                                                                   |
| admin-Ubah Status Pemesanan                            | `/admin/pemesanan/{id}/setujui-pembatalan`, `/tolak-pembatalan`                                                                                                                                 |
| admin-Perawatan Mobil Berkala                          | `/admin/servis`, `/admin/servis/status-unit`, `/admin/servis/{id}/selesai`, `/batal`, `/admin/servis/interval`                                                                                  |
| admin-Laporan Transaksi                                | `/admin/laporan`, `/admin/laporan/pdf`                                                                                                                                                          |
| Dashboard admin                                        | `/admin/dashboard`                                                                                                                                                                              |

## 15. Kode galat bisnis

| Kode                                            | Status | Arti                                                  |
| ----------------------------------------------- | ------ | ----------------------------------------------------- |
| `KREDENSIAL_SALAH`                              | 401    | email atau kata sandi salah                           |
| `AKUN_NONAKTIF`                                 | 403    | akun admin nonaktif                                   |
| `GANTI_PASSWORD_DULU`                           | 403    | admin masih memakai kata sandi sementara              |
| `EMAIL_TIDAK_TERDAFTAR`                         | 404    | email lupa kata sandi tidak ada                       |
| `LUAR_WILAYAH`                                  | 422    | tujuan paket sopir di luar Malang dan Batu            |
| `TAGIHAN_BELUM_LUNAS`                           | 409    | user masih punya tagihan tambahan belum lunas         |
| `PESANAN_MENUNGGU_ADA`                          | 409    | sudah ada satu pesanan menunggu pembayaran            |
| `UNIT_TIDAK_TERSEDIA`                           | 409    | tidak ada unit tipe itu pada tanggal tersebut         |
| `SOPIR_TIDAK_TERSEDIA`                          | 409    | sopir tidak cukup pada tanggal itu                    |
| `PEMBAYARAN_KEDALUWARSA`                        | 409    | lewat batas 1 jam                                     |
| `LEWAT_BATAS_H1`                                | 409    | pengajuan pembatalan lewat H-1                        |
| `STATUS_TIDAK_VALID`                            | 409    | aksi tidak boleh pada status pesanan saat ini         |
| `SUDAH_ADA_REVIEW`                              | 409    | review hanya sekali per pesanan                       |
| `TIPE_MASIH_DIPAKAI`                            | 409    | tipe masih punya unit atau pemesanan                  |
| `UNIT_PUNYA_PEMESANAN`                          | 409    | unit pernah dipakai pemesanan                         |
| `UNIT_SEDANG_DISEWA`                            | 409    | unit disewa, tidak bisa diubah atau diservis          |
| `UNIT_DALAM_SERVIS` / `UNIT_SUDAH_DALAM_SERVIS` | 409    | unit sudah dalam servis                               |
| `UNIT_TIDAK_BEBAS`                              | 409    | unit yang dipilih sudah tidak bebas saat serah terima |
| `PESANAN_TERDAMPAK`                             | 409    | peringatan, kirim ulang dengan `konfirmasi: true`     |
| `TAGIHAN_BELUM_LUNAS` (admin)                   | 409    | jaminan ditahan sampai tagihan lunas                  |
| `DATA_KOSONG`                                   | 409    | laporan PDF tidak ada datanya                         |
