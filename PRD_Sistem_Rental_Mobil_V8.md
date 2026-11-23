# PRD: Sistem Rental Mobil

**Versi:** 1.3 (draft, mengikuti flowchart V23 dan DFD level 0 dan 1; semua lewat web tanpa WhatsApp, tanpa pembatalan oleh admin, dan database transaction untuk ketersediaan) · **Status:** Untuk direview

## 1. Overview

Penyewaan mobil masih dicatat manual: ketersediaan unit, pembayaran, denda keterlambatan, dan jadwal servis tersebar di catatan terpisah. Sistem ini menyatukannya: pelanggan memilih tipe mobil, memesan, dan membayar lewat Midtrans, sementara admin mengelola tipe dan unit kendaraan, serah terima dan pengembalian beserta pemeriksaan unit, servis berkala, pembatalan, dan laporan transaksi dari satu dashboard.

Sistem ini **milik satu perusahaan rental**. Armada terdiri dari unit milik perusahaan dan unit titipan dari pemilik lain; pemilik unit titipan mendapat bagian dari uang sewa saat unitnya disewakan, dan perhitungan serta pembayarannya diurus di luar sistem.

Sistem ini dibuat untuk keperluan tugas, sehingga pembayaran memakai Midtrans mode sandbox dan semua komunikasi dengan pelanggan terjadi lewat web (tanpa WhatsApp). Teknologi yang diasumsikan adalah Laravel dan MySQL.

## 2. Goals & Non-Goals

**Goals**

- Pelanggan bisa memilih tipe mobil, memesan, dan membayar dalam < 5 menit
- Status unit (tersedia, disewa, dalam servis, tidak tersedia) selalu akurat dan berubah otomatis mengikuti pesanan dan servis
- Tagihan tambahan (denda keterlambatan, kekurangan BBM, kerusakan, biaya lain) dihitung sistem, bukan manual admin
- Setiap tindakan admin tercatat siapa pelakunya dan kapan
- Admin melihat status servis tiap unit (aman, segera, perlu servis) dan jadwal servis berikutnya
- Admin menarik laporan pendapatan per periode dan mengunduhnya sebagai PDF
- Semua tindakan pada pesanan dilakukan admin dari satu halaman Detail Pesanan, sesuai status

**Non-Goals (versi 1)**

- Pemesanan manual oleh admin untuk pelanggan yang datang langsung (semua pemesanan lewat akun pelanggan)
- Multi-cabang atau multi-perusahaan rental
- Penjadwalan dan penugasan sopir tertentu (sistem hanya membatasi jumlah sopir yang tersedia per tanggal)
- Tarif per rute untuk paket dengan sopir di luar Malang dan Batu (kelebihan biaya dicatat admin sebagai biaya tambahan saat pengembalian)
- Batas kilometer dan biaya kelebihan kilometer (sewa lepas kunci tanpa batas km, odometer hanya dicatat)
- Unggahan KTP atau SIM oleh pelanggan (jaminan dicek fisik oleh admin saat serah terima) dan batas usia penyewa
- Ubah jadwal pesanan (mengubah jadwal berarti membatalkan pesanan lalu memesan ulang)
- Pelacakan GPS kendaraan real-time
- Aplikasi mobile native (tampilan HP hanya lewat browser, tanpa unduhan aplikasi)
- Perhitungan dan pembayaran bagi hasil ke pemilik unit titipan (diurus di luar sistem)
- Refund otomatis untuk pembayaran virtual account (admin mentransfer di luar sistem lalu menandai “refund selesai” di web; refund QRIS lewat Midtrans)
- Perpanjangan otomatis dan pengembalian lebih awal. Tombol Perpanjang sewa di Detail Pesanan (hanya saat Berjalan) hanya membuka Katalog dengan tanggal mulai sehari setelah tanggal kembali, jadi perpanjangan tetap berupa pesanan baru (lihat bagian 12); pengembalian lebih awal tidak mendapat refund
- Promo, diskon, dan tarif musiman (tarif tetap sepanjang tahun)
- Notifikasi otomatis ke pelanggan lewat email atau WhatsApp, selain nota di layar, email verifikasi, dan email reset kata sandi
- Panel Kelola Admin dan level admin (akun admin dikelola pengembang lewat database, semua admin berhak sama)
- Mengubah email akun lewat Profil (email tetap sejak registrasi)
- Fitur “ingat saya” pada login
- Pembatalan pesanan oleh admin (hanya pelanggan yang membatalkan; admin menyetujui atau menolak pengajuan pembatalan)
- Komunikasi lewat WhatsApp atau tombol Hubungi admin (semua lewat web; kontak kantor hanya teks di halaman Kontak)
- Unit pengganti, penanda “perlu tindakan”, dan email ke admin (pelanggan yang sudah membayar pasti mendapat unit lewat database transaction)

## 3. Target User

| User | Deskripsi | Kebutuhan utama |
| --- | --- | --- |
| Guest | Pengunjung tanpa akun | Lihat katalog tipe mobil dan detail tarif |
| User / Pelanggan (login) | Penyewa perorangan | Pesan mobil, bayar lewat Midtrans, batalkan atau ajukan pembatalan, lihat riwayat dan detail pesanan, beri review, ubah profil dan kata sandi |
| Admin | Staf rental, lebih dari satu orang dan masing-masing punya akun sendiri | Kelola tipe dan unit, serah terima dan pengembalian, servis berkala, proses pembatalan, tagihan tambahan dan jaminan, laporan |

Pengembang bukan pengguna aplikasi. Pengembang hanya membuat dan menonaktifkan akun admin langsung di database.

## 4. Hak Akses

| Fitur | Guest | User Login | Admin |
| --- | --- | --- | --- |
| Lihat katalog dan detail tipe mobil | Ya | Ya | Ya |
| Lihat halaman statis (Beranda, Syarat dan Ketentuan, FAQ, Kebijakan Privasi, Kontak) | Ya | Ya | Ya |
| Registrasi akun (dengan verifikasi email) | Ya | - | - |
| Login (satu halaman untuk semua peran; email dan kata sandi) | Ya | Ya | Ya (akun sendiri, dibuat pengembang, tanpa registrasi publik) |
| Lupa kata sandi (lewat email) | Ya | Ya | Ya |
| Pesan mobil dan bayar (Midtrans) | Tidak | Ya | Tidak |
| Batalkan pesanan (menunggu pembayaran) atau ajukan pembatalan (dikonfirmasi, paling lambat H-1) | Tidak | Ya (pesanan sendiri) | Tidak |
| Lihat daftar dan Detail Pesanan | Tidak | Ya (miliknya) | Ya (semua pesanan) |
| Beri rating dan review | Tidak | Ya | Tidak |
| Unduh atau cetak nota (PDF) | Tidak | Ya (pesanan sendiri) | Tidak |
| Update profil | Tidak | Ya | Tidak |
| Ganti kata sandi (akun email) | Tidak | Ya | Ya |
| Dashboard admin | Tidak | Tidak | Ya |
| Kelola tipe, unit, dan detail kendaraan | Tidak | Tidak | Ya |
| Serahkan unit (plat nomor, jaminan, odometer awal, kondisi awal) | Tidak | Tidak | Ya |
| Proses pembatalan (setujui atau tolak pengajuan) | Tidak | Tidak | Ya |
| Pengembalian dan pemeriksaan unit, tagihan tambahan | Tidak | Tidak | Ya |
| Tandai tagihan dibayar, kirim pengingat, kembalikan jaminan | Tidak | Tidak | Ya |
| Tampilkan atau sembunyikan review di katalog | Tidak | Tidak | Ya |
| Servis berkala (catat, selesai, batal, interval) | Tidak | Tidak | Ya |
| Laporan transaksi dan PDF | Tidak | Tidak | Ya |
| Kelola akun admin (tambah, nonaktifkan) | Tidak | Tidak | Tidak (pengembang lewat database) |

## 5. User Stories

- Sebagai **guest**, saya ingin melihat katalog dan tarif tipe mobil tanpa login, supaya bisa membandingkan harga sebelum membuat akun.
- Sebagai **calon pelanggan**, saya ingin mendaftar dengan email yang belum dipakai, supaya bisa memesan mobil.
- Sebagai **calon pelanggan**, saya ingin email saya diverifikasi lewat tautan, supaya akun tidak bisa dipakai orang lain.
- Sebagai **pelanggan**, saya ingin memilih tipe, tanggal, dan durasi sewa lalu membayar lewat Midtrans (QRIS atau virtual account), supaya tidak perlu datang ke kantor.
- Sebagai **pelanggan**, saya ingin menerima nota setelah pembayaran berhasil dan bisa mengunduh atau mencetaknya sebagai PDF, supaya punya bukti transaksi.
- Sebagai **pelanggan**, saya ingin melihat tipe yang tersedia beserta sisa unit pada tanggal sewa yang saya pilih, supaya tidak memilih tipe yang sudah penuh.
- Sebagai **pelanggan**, saya ingin membatalkan langsung pesanan yang belum dibayar, dan mengajukan pembatalan pesanan yang sudah dikonfirmasi (paling lambat H-1) lewat formulir di web (alasan, dan untuk virtual account data rekening refund).
- Sebagai **pelanggan**, saya ingin melihat riwayat dan detail pesanan (tahapan, biaya, tagihan tambahan) serta memberi rating dan review untuk pesanan selesai.
- Sebagai **pelanggan**, saya ingin memperpanjang sewa dengan memesan lagi, supaya unit yang sedang saya pakai bisa tetap saya pakai.
- Sebagai **admin**, saya ingin login dengan akun saya sendiri, supaya setiap pesanan yang saya proses tercatat atas nama saya.
- Sebagai **admin**, saya ingin menambah, mengubah, dan menghapus tipe dan unit mobil beserta detail internalnya, supaya katalog sesuai armada.
- Sebagai **admin**, saya ingin mengisi atau mengganti plat nomor saat menyerahkan unit, serta mencatat jaminan, odometer, BBM, dan kondisi awal, supaya serah terima tercatat.
- Sebagai **admin**, saya ingin menyelesaikan pesanan lewat form pengembalian dan pemeriksaan, supaya sistem menghitung tagihan tambahan dengan benar.
- Sebagai **admin**, saya ingin menahan jaminan selama tagihan tambahan belum dibayar dan mengembalikannya setelah lunas, supaya tagihan terjamin.
- Sebagai **admin**, saya ingin menyetujui atau menolak pengajuan pembatalan dari Detail Pesanan, supaya status pesanan dan tanggal sewa ikut diperbarui.
- Sebagai **admin**, saya ingin melihat status servis tiap unit, mencatat, menyelesaikan, atau membatalkan servis, supaya servis tidak terlewat dan riwayat perawatan benar.
- Sebagai **admin**, saya ingin menarik laporan transaksi (langsung 7 hari terakhir, atau periode lain) dan mengunduhnya sebagai PDF, supaya mudah dilaporkan ke pimpinan.

## 6. Functional Requirements

### 6.1 Autentikasi & Registrasi

| Requirement | Prioritas |
| --- | --- |
| Registrasi (hanya user): isi nama, email, WhatsApp, domisili (provinsi dan kota), kata sandi (minimal 8 karakter dan ada angka), dan konfirmasi; sistem cek email belum dipakai, simpan akun belum terverifikasi dan pelanggan, kirim tautan verifikasi, lalu tampil “Cek email Anda”. User tidak langsung login | Must |
| Verifikasi email: tautan sekali pakai berlaku 12 jam, tombol Kirim ulang maksimal 3 kali per jam, setelah berhasil user diarahkan ke Login. Akun belum terverifikasi tidak bisa login (tampil “Email belum diverifikasi” beserta Kirim ulang) | Must |
| Akun yang tidak diverifikasi dihapus otomatis setelah 30 hari; mendaftar ulang dengan email yang sama sebelum itu menggantikan data lama, kecuali dalam 60 menit pertama sejak akun dibuat (ditolak, agar akun baru tidak bisa ditimpa orang lain) | Must |
| Login (email dan kata sandi) dengan validasi akun dan status aktif, satu halaman untuk admin dan user; peran menentukan tujuan (user ke Home atau Katalog, admin ke Dashboard) | Must |
| Akun admin lebih dari satu dan tiap admin punya akun sendiri. Akun dibuat pengembang lewat database (seeder), kata sandi disimpan dalam bentuk hash, dan kata sandi sementara diganti admin sendiri setelah login pertama | Must |
| Tidak ada registrasi admin, panel Kelola Admin, maupun level admin; semua admin berhak sama | Must |
| Admin yang berhenti dinonaktifkan oleh pengembang, tidak dihapus, supaya riwayat pesanan yang pernah dia proses tetap terbaca. Akun nonaktif tidak bisa login | Must |
| Mode guest: lihat katalog dan detail tipe tanpa akun | Must |
| Guest yang menekan Pesan dikirim ke Login; setelah berhasil user kembali ke Katalog dengan isian tanggal dan durasi kosong (ketersediaan dihitung ulang), dan tujuan itu dihapus setelah dipakai | Must |
| Logout selalu ke Home | Must |
| Masa login berupa batas tidak aktif: user 60 menit, admin 30 menit, tanpa fitur “ingat saya”. Setiap permintaan yang berhasil memperpanjang masa login; setelah habis (kode BELUM_LOGIN) pengguna diminta login lagi | Must |
| Ganti kata sandi (akun email, user dan admin): kata sandi lama, baru (minimal 8 karakter dan ada angka), dan konfirmasi. Setelah berhasil, token login di perangkat lain dicabut | Must |
| Lupa kata sandi (akun email, termasuk admin): tautan reset lewat email berlaku 60 menit dan sekali pakai. Pesan selalu sama, terdaftar atau tidak (“Jika email terdaftar, instruksi reset kata sandi telah dikirim”), agar email tidak bisa ditebak. Reset berhasil sekaligus memverifikasi email dan mencabut semua token login | Should |
| Email tidak bisa diubah lewat Profil | Must |
| Pembatasan percobaan (kode TERLALU_BANYAK_PERCOBAAN, status 429): login 5 per menit per IP dan 10 per jam per email; lupa kata sandi 5 per menit per IP dan 3 per jam per email; reset kata sandi 5 per menit per IP; registrasi 10 per jam per IP; kirim ulang verifikasi 3 per jam | Should |

### 6.2 Data Kendaraan

| Requirement | Prioritas |
| --- | --- |
| Dua tingkat data: tipe (nama, merk, kursi, bahan bakar bensin atau diesel, tarif dengan sopir per hari, tarif lepas kunci manual dan matic per hari, sampai 3 foto) dan unit (tipe, plat nomor, tahun, warna, transmisi, odometer, status) | Must |
| Tambah, ubah, hapus tipe dan unit, dengan validasi “data sudah benar?” sebelum simpan | Must |
| Plat nomor unik; jika sudah ada tampil “plat nomor sudah terdaftar”. Jika data yang sama ada di tempat sampah, tampil pop-up tiga pilihan: pulihkan data lama, hapus permanen lalu buat baru, atau batal | Must |
| Hapus tipe atau unit memindahkannya ke tempat sampah (soft delete), bukan menghapus permanen; konfirmasi “yakin hapus?”. Hapus unit ditolak hanya jika unit sedang disewa atau dalam servis; hapus tipe ditolak jika masih punya unit aktif atau pesanan | Must |
| Tempat sampah (admin): daftar tipe dan unit yang dihapus, bisa dipulihkan atau dihapus permanen dengan konfirmasi. Unit yang punya riwayat pesanan, serah terima, atau servis tidak bisa dihapus permanen. Data di tempat sampah dibuang otomatis setelah 30 hari (HARI_SAMPAH), kecuali yang punya riwayat | Must |
| Detail internal unit (hanya admin): pemilik, status kepemilikan (milik perusahaan atau titipan), no. rangka, no. mesin, no. BPKB, masa STNK, catatan | Must |
| Unit titipan: pemilik unit mendapat bagian dari uang sewa; perhitungan dan pembayarannya diurus di luar sistem | Could |
| Status unit: tersedia, dalam servis, tidak tersedia (diatur admin, ditolak jika unit sedang disewa); “disewa” mengikuti pesanan berstatus Berjalan dan tetap berlaku selama unit belum dikembalikan, termasuk saat terlambat | Must |
| Unit dalam servis atau tidak tersedia tidak dihitung pada cek ketersediaan dan hilang dari katalog | Must |
| Servis atau tanda tidak tersedia selalu boleh dicatat. Jika ada pesanan Dikonfirmasi yang membutuhkan unit itu, tampil peringatan beserta daftar pesanan terdampak, dan admin bertanggung jawab bila tetap melanjutkan | Must |
| Unit yang dibawa keluar kota (sewa lepas kunci) ditandai Tidak tersedia oleh admin selama perjalanan | Must |
| Tampilkan data tipe dan unit terbaru setelah setiap aksi | Must |
| Filter dan pencarian | Should |

### 6.3 Servis Mobil Berkala

| Requirement | Prioritas |
| --- | --- |
| Halaman Servis berisi ringkasan (unit dalam servis, unit tersedia, biaya bengkel, biaya ditagih), form catat servis, daftar sedang servis, pengingat berkala, dan riwayat servis | Must |
| Status servis per unit: aman, segera, perlu servis, atau belum ada data; dihitung tiap halaman dibuka, tidak disimpan | Must |
| **Catat servis:** admin memilih unit; jika unit sedang disewa tampil “unit sedang disewa, perawatan ditunda”; jika sudah dalam servis ditolak; jika ada pesanan Dikonfirmasi yang membutuhkan unit itu tampil peringatan tetapi servis tetap boleh dicatat | Must |
| Input servis: jenis, tingkat, tanggal masuk, bengkel, odometer (tidak boleh lebih kecil dari sebelumnya), keterangan; tidak ada estimasi biaya, biaya baru diisi saat servis selesai | Must |
| Setelah valid: simpan servis, status unit “dalam perawatan” (hilang dari katalog), odometer unit diperbarui, tampil di daftar sedang servis | Must |
| Servis juga dibuat otomatis dari pengembalian saat admin mencentang “unit perlu servis”, dengan asal pesanan tercatat | Must |
| **Selesai servis:** pilih komponen (bisa banyak), biaya bengkel, km saat ini; biaya ditagih ke penyewa diambil dari kerusakan pada pengembalian (servis dari pengembalian). Sistem menghitung biaya yang ditanggung perusahaan dan jadwal berikutnya, ubah status unit “tersedia”, tampil riwayat | Must |
| **Batal servis:** status servis “dibatalkan” dan status unit “tersedia” | Must |
| Interval servis (km) bisa diatur admin; status pengingat dihitung ulang | Must |
| Riwayat perawatan per unit | Must |

### 6.4 Pemesanan & Pembayaran

| Requirement | Prioritas |
| --- | --- |
| Katalog tipe dan halaman detail tipe dengan foto (sampai 3), spesifikasi singkat, tarif (dengan sopir, lepas kunci manual, lepas kunci matic), rata-rata rating, dan sisa unit tersedia pada tanggal yang dipilih; katalog bisa difilter menurut kursi dan transmisi | Must |
| Urutan pemesanan: user mengisi tanggal mulai dan durasi lebih dulu, lalu sistem menampilkan tipe yang tersedia beserta sisa unit, baru user memilih tipe | Must |
| Form pesanan: jenis sewa, jam jemput, catatan. Lepas kunci: transmisi (manual atau matic), cara terima unit (ambil di kantor atau diantar; lokasi jemput hanya diisi bila diantar), dan persetujuan syarat jaminan (domisili diambil dari profil dan disalin ke pesanan). Dengan sopir: lokasi jemput selalu diisi dan tujuan perjalanan berupa teks bebas (boleh lebih dari satu). Ringkasan biaya dihitung per jenis sewa: lepas kunci memakai tarif lepas kunci manual atau matic × hari, dengan sopir memakai tarif sopir × hari (sudah termasuk sopir, BBM, dan lembur); tanggal kembali = mulai + durasi − 1 untuk keduanya | Must |
| Syarat dan ketentuan sewa wajib dicentang untuk semua jenis sewa (isi: denda, kerusakan, BBM, pembatalan, tanggung jawab tilang dan kerusakan, serta rincian biaya yang ditanggung penyewa) | Must |
| Pelanggan yang masih punya tagihan tambahan belum lunas tidak bisa memesan, dan satu pelanggan hanya boleh punya 1 pesanan Menunggu pembayaran dalam satu waktu | Must |
| Tanggal mulai paling cepat besok dan paling jauh 1 bulan (30 hari) dari hari pemesanan; durasi 1 sampai 30 hari; tanggal mulai dihitung sebagai hari pertama sewa, dan tanggal kembali = tanggal mulai + durasi − 1 | Must |
| Cara terima unit hanya untuk **lepas kunci**: ambil di kantor (tanpa lokasi jemput) atau diantar (lokasi jemput berupa alamat bebas). Untuk **dengan sopir**, mobil dan sopir menjemput di lokasi jemput. Antar atau jemput gratis sampai batas Tlogomas; di luar itu dikenakan biaya antar yang dicatat admin sebagai biaya tambahan saat pengembalian (jenis “Biaya antar”) | Must |
| Jam jemput: sewa lepas kunci dalam jam operasional (07.00 sampai 21.00, setiap hari); sewa dengan sopir bebas sesuai pelanggan. Untuk keduanya batas kembali tetap tanggal kembali pukul 23.59 | Must |
| Tarif tetap tanpa promo atau diskon. Untuk paket dengan sopir, tarif hanya mencakup BBM dan lembur sopir; makan, tol, parkir, dan penginapan sopir ditanggung penyewa | Must |
| Paket dengan sopir untuk tujuan di Malang dan Batu (Kota Malang, Kabupaten Malang, Kota Batu), tarif per hari per mobil, tujuan boleh lebih dari satu. Tujuan diisi bebas oleh pelanggan dan tidak diperiksa sistem; syaratnya tertulis di Syarat dan Ketentuan, dan kelebihan biaya (tol, jarak di luar Malang dan Batu, dan lain-lain) dicatat admin sebagai biaya tambahan saat pengembalian | Must |
| Jumlah sopir terbatas: batasnya disimpan di pengaturan (diisi pengembang), dan paket dengan sopir hanya bisa dipesan jika sopir tersedia pada tanggal itu. Pengaturan kosong berarti tidak dibatasi | Must |
| Sewa lepas kunci tanpa batas kilometer | Must |
| Sistem menghitung ringkasan biaya (tarif per hari × durasi) | Must |
| Cek unit tersedia per tipe dan tanggal: jumlah unit tipe itu harus melebihi pesanan yang bentrok (Menunggu pembayaran selama masa berlakunya, Dikonfirmasi, Berjalan, Pengajuan pembatalan); jika tidak ada tampil “mobil sudah dipesan pada tanggal tersebut” dan kembali ke form | Must |
| Pesanan dibuat berstatus “menunggu pembayaran” dan menahan unit selama maksimal 1 jam, lalu sistem membuat transaksi Midtrans | Must |
| Pembayaran lunas sebesar total biaya sewa, tanpa DP | Must |
| Pembayaran lewat Midtrans dengan QRIS atau virtual account, masa berlaku paling lama 1 jam. Notifikasi berhasil mengubah status menjadi “dikonfirmasi” otomatis dan nota tampil (bisa diunduh atau dicetak sebagai PDF) | Must |
| Jika pembayaran kedaluwarsa: pembayaran berstatus gagal, pesanan otomatis menjadi “dibatalkan”, dan unit yang ditahan dilepas. Tidak ada lagi status menunggu pembayaran yang bisa dibayar ulang | Must |
| **Database transaction:** pembuatan pesanan berjalan dalam satu transaksi dengan penguncian tipe mobil: cek sisa unit, simpan pesanan, dan terbitkan tagihan pembayaran sebagai satu paket. Siapa yang tiba lebih dulu mendapat jatah; yang kedua melihat “mobil sudah dipesan pada tanggal tersebut” dan tidak mendapat tagihan. Saat pembayaran masuk, sisa unit dicek ulang di dalam transaksi; jika pembayaran terlambat dan jatah sudah habis, pembayaran dibatalkan otomatis dan direfund penuh | Must |
| Pelanggan yang sudah membayar pasti mendapat plat saat Serahkan unit. Jika ternyata tidak ada plat bebas, itu kesalahan admin (mis. menandai unit tidak tersedia atau servis padahal masih ada pesanan, padahal sistem sudah memberi peringatan) | Must |
| Tanggal terblokir saat pesanan Dikonfirmasi (dan tetap selama Pengajuan pembatalan); unit berstatus “disewa” saat pesanan Berjalan | Must |
| Plat nomor unit ditetapkan atau diganti admin saat Serahkan unit; user melihat plat setelah itu | Must |
| Pesanan baru muncul di halaman Pemesanan admin | Must |
| Status pesanan: menunggu pembayaran, dikonfirmasi, berjalan, selesai, pengajuan pembatalan, dibatalkan | Must |
| Perpanjangan sewa tanpa fitur khusus: pelanggan membuat pesanan baru yang mulai sehari setelah tanggal kembali pesanan lama, lalu admin menetapkan plat unit yang sama saat serah terima (lihat 6.6) | Should |

### 6.5 Pembatalan Pemesanan

| Requirement | Prioritas |
| --- | --- |
| **Sisi user:** tombol batal ada di halaman Detail Pesanan, bukan di daftar | Must |
| Pesanan “menunggu pembayaran”: Batalkan pesanan langsung dengan konfirmasi “yakin batalkan?”; status “dibatalkan”, unit dilepas | Must |
| Pesanan “dikonfirmasi”: Ajukan pembatalan paling lambat H-1 sebelum tanggal mulai (lewat itu tampil “pesanan tidak bisa dibatalkan”), dengan konfirmasi dan formulir: alasan, dan untuk pembayaran virtual account bank, nomor rekening, dan nama rekening untuk refund; status menjadi “pengajuan pembatalan” (status sebelumnya disimpan) dan tanggal tetap terblokir sampai admin memproses | Must |
| Pesanan “berjalan” atau “selesai” tidak bisa dibatalkan. Pelanggan yang tidak datang pada tanggal mulai tidak mendapat pembatalan maupun refund: pesanan ditutup otomatis oleh sistem (tugas terjadwal) setelah lewat tanggal kembali, berstatus “dibatalkan” dengan alasan tidak diambil, dan pembayaran tetap lunas (tidak dikembalikan) | Must |
| **Sisi admin:** buka Pemesanan, pilih pesanan berstatus “pengajuan pembatalan” di Detail Pesanan, lalu Setujui atau Tolak (keduanya dengan konfirmasi) | Must |
| Pembatalan yang disetujui mendapat dana kembali penuh, tanpa potongan | Must |
| Setujui: status “dibatalkan”, unit dilepas (bisa dipesan lagi), pembayaran “dikembalikan”; refund QRIS lewat Midtrans, refund virtual account ditransfer admin di luar sistem ke rekening yang diisi pelanggan, lalu admin menandai “refund selesai” di Detail Pesanan | Must |
| Tolak: status kembali ke status sebelum pengajuan | Must |
| Admin tidak bisa membatalkan pesanan sama sekali: pembatalan hanya dari pelanggan, dan admin hanya menyetujui atau menolak pengajuan | Must |
| Catat status pengembalian dana pada data pembayaran | Should |

### 6.6 Serah Terima, Pengembalian & Pemeriksaan Unit

| Requirement | Prioritas |
| --- | --- |
| **Serahkan unit** (pesanan Dikonfirmasi): admin memilih plat nomor dari daftar unit tipe itu yang kosong pada tanggal sewa (disaring sistem, boleh diganti). Untuk lepas kunci admin mencatat jaminan, no. identitas, odometer awal, dan BBM awal; untuk dengan sopir tidak ada jaminan. Keduanya mencatat kondisi awal (foto opsional), lalu status menjadi “berjalan” dan unit “disewa”. User melihat plat nomor setelah itu | Must |
| Cara terima unit (ambil di kantor atau diantar) dan lokasi jemput tampil sebagai data di Detail Pesanan; tidak ada pengecekan khusus saat Serahkan unit. Biaya antar di luar batas Tlogomas dicatat admin sebagai biaya tambahan (jenis “Biaya antar”) saat pengembalian | Must |
| Persetujuan dan penolakan pengajuan pembatalan dilakukan di halaman Detail Pesanan (tidak ada halaman Ubah Status terpisah) | Must |
| Detail Pesanan user menampilkan status Berjalan, Pengajuan pembatalan, dan Selesai dalam satu alur (info sesuai status). Tombol Perpanjang sewa hanya tampil saat Berjalan dan membuka Katalog dengan tanggal mulai sehari setelah tanggal kembali (perpanjangan tetap berupa pesanan baru); tombol Beri penilaian hanya saat Selesai dan belum pernah menilai | Must |
| Catatan kondisi awal ringkas dan foto opsional dicatat saat Serahkan unit (tabel serah\_terima) | Must |
| Lepas kunci: jaminan diterima: a. KTP asli, b. SIM C asli, c. salah satu dari KTM / KK / NPWP / BPJS / Paspor, serta d. sepeda motor dan STNK (domisili Malang) atau e. bukti booking transportasi umum (pesawat, kereta, atau bus) dan bukti booking hotel (domisili luar kota), no. identitas penyewa diisi admin, odometer awal (tidak boleh kosong), BBM awal | Must |
| Domisili pesanan disalin dari profil saat memesan. Admin memeriksa KTP asli dan boleh menyesuaikan domisili pesanan jika berbeda, lalu meminta jaminan yang sesuai; perubahan dicatat di riwayat aktivitas. Jika syarat jaminan tidak terpenuhi, unit tidak diserahkan dan pesanan diperlakukan seperti pelanggan yang tidak datang (tanpa pembatalan dan refund) | Must |
| Verifikasi bukti booking (aturan kerja admin, tanpa fitur khusus): pelanggan menunjukkan bukti asli di akunnya sendiri saat serah terima; admin mencocokkan nama dengan KTP, tanggal dengan masa sewa, dan kode booking ke sumbernya (situs atau aplikasi resmi, atau telepon hotel dan operator), memastikan booking masih aktif, lalu mencatat hasilnya di catatan admin. Jika tidak bisa dipastikan, unit tidak diserahkan | Must |
| Pesanan lanjutan (perpanjangan): odometer awal diambil dari odometer akhir pesanan sebelumnya dan jaminan yang masih dipegang dicatat ulang | Must |
| **Selesaikan pesanan** (pesanan Berjalan): form lima bagian, yaitu pengembalian, pemeriksaan kondisi, biaya tambahan lain, jaminan, dan konfirmasi | Must |
| Pengembalian: tanggal dan jam kembali (pelanggan boleh mengembalikan kapan saja sampai pukul 23.59 tanggal kembali, admin mencatat jam aktual), denda keterlambatan per hari (lewat pukul 23.59 tanggal kembali dihitung 1 hari, hari berikutnya bertambah 1 hari); lepas kunci juga odometer akhir (tidak lebih kecil dari awal), BBM saat kembali, biaya kekurangan BBM, kebersihan | Must |
| Pemeriksaan 8 bagian (depan, belakang, sisi kiri, sisi kanan, atap, kaca dan lampu, ban dan velg, interior): kondisi Baik / Lecet / Penyok / Retak / Rusak, biaya perbaikan, keterangan; foto wajib jika ada kerusakan | Must |
| Biaya tambahan lain: cuci, kelengkapan hilang, tol, parkir, lainnya | Must |
| Konfirmasi: catatan admin, opsi “unit perlu servis” (tingkat dan keperluan), persetujuan penyewa (wajib), status pembayaran tagihan (belum dibayar, tunai, transfer) | Must |
| Sistem menghitung tagihan tambahan (denda keterlambatan + kekurangan BBM + kerusakan + biaya lain) dan menampilkan pratinjau tagihan sebelum admin menyimpan | Must |
| Jaminan fisik ditahan selama tagihan tambahan belum dibayar. Jaminan ditandai dikembalikan otomatis jika tidak ada tagihan, atau saat tagihan ditandai lunas; tombol Kembalikan jaminan manual hanya untuk data lama. Jika tagihan tidak dibayar, jaminan ditahan sampai lunas dan ditinjau pusat setelah 30 hari | Must |
| Pembayaran tagihan tambahan lewat transfer ke satu rekening resmi perusahaan atau tunai, lalu ditandai admin | Must |
| Tanggung jawab penyewa: tilang dan kerusakan ditanggung penyewa; kecelakaan, mogok, dan derek mengikuti kebijakan pusat. Pemeriksaan kondisi tetap dilakukan untuk paket dengan sopir | Must |
| Sewa dengan sopir tidak memakai jaminan, sehingga tagihan yang belum dibayar ditangani lewat banner tagihan di Detail Pesanan user dan pemblokiran pesanan baru sampai lunas | Must |
| Setelah disimpan: status pesanan “selesai”; unit “tersedia”, atau “dalam servis” (servis dengan asal pesanan) jika perlu servis | Must |
| Tagihan tambahan tampil di Detail Pesanan user (bayar tunai atau transfer ke rekening resmi); admin bisa Tandai tagihan dibayar atau Kembalikan jaminan | Must |
| Perpanjangan: pada hari terakhir pesanan lama admin menyelesaikan pesanan itu, tagihan tambahannya harus lunas dulu, jaminan ditandai dikembalikan hanya sebagai pencatatan (fisiknya tetap di admin) lalu dicatat ulang di pesanan baru, kemudian admin menyerahkan unit pesanan baru dengan plat yang sama | Should |

### 6.7 Detail Pesanan, Riwayat, Review, Profil & Dashboard

| Requirement | Prioritas |
| --- | --- |
| Halaman Pemesanan user: tab Berlangsung dan Riwayat, kartu dengan garis tahap, satu tombol utama bila ada | Must |
| Halaman Pemesanan admin: daftar hanya menampilkan status (tanggal, pesanan, pelanggan, unit, status) dengan filter status; semua tindakan ada di Detail Pesanan | Must |
| Halaman Detail Pesanan (user dan admin): tombol sesuai status dan peran; kartu tahapan, data pesanan, pembayaran, jaminan, rincian biaya, hasil pemeriksaan, riwayat aktivitas (siapa dan kapan, diambil dari log aktivitas) | Must |
| Detail Pesanan user: tombol Unduh atau cetak nota (PDF) untuk pesanan Dikonfirmasi ke atas | Must |
| Detail Pesanan admin: data pelanggan, catatan admin internal, tautan “lihat pesanan lain pelanggan ini” | Must |
| Form rating (1-5) dan komentar, validasi sebelum disimpan, satu review per pesanan | Must |
| Review hanya untuk pesanan berstatus “selesai” | Must |
| Review hanya dilihat admin secara bawaan. Admin bisa memilih menampilkan review tertentu di katalog | Could |
| Profil: lihat dan ubah foto (atau hapus foto), nama, WhatsApp, provinsi dan kota (domisili); email hanya dilihat dan tidak bisa diubah; konfirmasi “yakin update?” sebelum simpan | Must |
| Dashboard admin: hitungan status pesanan, unit tersedia dan dalam servis, daftar pengajuan pembatalan, tagihan belum dibayar, dan jaminan siap dikembalikan, serta pengingat servis | Must |
| Halaman statis: Beranda, Syarat dan Ketentuan, FAQ, Kebijakan Privasi, dan Kontak (alamat atau peta kantor, nomor telepon, dan email, hanya teks) | Should |

### 6.8 Laporan Transaksi

| Requirement | Prioritas |
| --- | --- |
| Laporan langsung menampilkan 7 hari terakhir; pilihan periode 7 hari, 30 hari, bulan ini, bulan lalu (menurut tanggal mulai sewa) dan filter status | Must |
| Jika data kosong tampil “tidak ada data pada periode ini”, lalu admin memilih periode lain | Must |
| Ringkasan: total pendapatan (hanya pesanan Dikonfirmasi, Berjalan, Selesai; sewa ditambah tagihan tambahan), jumlah transaksi, jumlah dibatalkan, rincian per status | Must |
| Tabel pesanan pada periode terpilih | Must |
| Unduh laporan sebagai PDF | Must |
| Grafik pendapatan per periode di dashboard admin | Could |

## 7. Perhitungan

**Biaya sewa:**

```latex
\text{total biaya} = \text{tarif per hari (sesuai tipe dan jenis sewa)} \times \text{durasi sewa (hari)}
```

```latex
\text{tanggal kembali} = \text{tgl mulai} + \text{durasi (hari)} - 1
```

Tanggal mulai dihitung sebagai hari pertama. Contoh: sewa 1 hari yang mulai tanggal 1 harus kembali tanggal 1 paling lambat pukul 23.59; sewa 3 hari yang mulai tanggal 10 harus kembali tanggal 12 paling lambat pukul 23.59.

**Tagihan tambahan saat pengembalian:**

```latex
\text{denda keterlambatan} = \text{hari terlambat} \times \text{tarif sewa per hari}
```

```latex
\text{tagihan tambahan} = \text{denda keterlambatan} + \text{kekurangan BBM} + \text{biaya kerusakan} + \text{biaya tambahan lain}
```

- **Tarif denda** = harga sewa mobil yang sedang disewa (tarif per hari sesuai tipe dan jenis sewa)
- **Hari terlambat** = selisih tanggal kalender antara tanggal kembali aktual dan tanggal kembali jadwal; selama masih pada tanggal kembali (sampai pukul 23.59) tidak terlambat, begitu lewat pukul 00.00 langsung dihitung 1 hari, dan tiap pergantian hari berikutnya menambah 1 hari; 0 jika tepat waktu. Contoh: sewa mulai tanggal 1 durasi 1 hari, kembali tanggal 2 pukul 00.10, maka denda = 1 hari × harga sewa per hari
- **Biaya kerusakan** = jumlah biaya perbaikan tiap bagian pemeriksaan, ditentukan dan diisi admin
- **Kekurangan BBM** (khusus lepas kunci) dan **biaya tambahan lain** (cuci, kelengkapan hilang, tol, parkir) diisi admin
- **Pendapatan laporan** = biaya sewa + tagihan tambahan, hanya untuk pesanan Dikonfirmasi, Berjalan, dan Selesai
- **Refund pembatalan** = 100% dari jumlah yang dibayar, tanpa potongan

**Jadwal servis berikutnya** (dihitung saat servis selesai):

```latex
\text{km berikutnya} = \text{odometer saat selesai} + \text{interval km}
```

```latex
\text{tgl berikutnya} = \text{tgl selesai servis} + \text{interval bulan}
```

**Status servis di halaman servis:**

- **Perlu servis**: odometer unit sudah mencapai km berikutnya, atau tanggal hari ini sudah mencapai tanggal berikutnya
- **Segera**: sisa kurang dari atau sama dengan 1.000 km, atau sisa kurang dari atau sama dengan 30 hari
- **Aman**: selain itu
- **Belum ada data servis**: unit belum punya servis yang selesai

Interval km diatur admin (bawaan 5.000 km) dan interval waktu 6 bulan. Selisih biaya servis = biaya yang ditagih ke penyewa dikurangi biaya bengkel.

## 8. Model Data

Dua puluh tabel sesuai ERD V20 (tipe dan kendaraan memakai kolom deleted_at untuk tempat sampah) (bentuk bunga mekar dengan pemesanan di pusat). Detail internal kendaraan dipisah ke tabel sendiri karena hanya boleh dilihat admin.

| Tabel | Isi utama | Relasi |
| --- | --- | --- |
| akun | email, password, role, aktif, email\_terverifikasi\_at, wajib\_ganti\_password | 1 akun → 1 pelanggan (akun admin tanpa pelanggan); 1 akun admin → banyak log\_aktivitas; banyak token\_akun |
| token\_akun | id\_akun, jenis (verifikasi\_email atau reset\_password), token\_hash, kedaluwarsa\_at, dipakai\_at | banyak per akun; sekali pakai |
| log\_aktivitas | id\_akun (admin), aksi, objek, id\_objek, waktu | banyak per akun admin |
| pelanggan | nama, no\_hp, no\_identitas, provinsi, kota, foto | milik 1 akun; banyak pemesanan |
| tipe\_kendaraan | nama, merk, kursi, bahan\_bakar, harga\_sopir\_per\_hari, harga\_lepas\_kunci\_manual, harga\_lepas\_kunci\_matic | banyak kendaraan, tipe\_foto, dan pemesanan |
| kendaraan (unit) | id\_tipe, plat\_nomor, tahun, warna, transmisi, km\_terakhir, status | 1 detail\_kendaraan; banyak perawatan, pemesanan, dan serah\_terima |
| detail\_kendaraan | nama\_pemilik, status\_kepemilikan, no\_rangka, no\_mesin, no\_bpkb, masa\_berlaku\_stnk, catatan | 1-1 dengan kendaraan; akses admin saja |
| pemesanan | id\_pelanggan, id\_tipe, id\_kendaraan, id\_admin, jenis\_sewa, transmisi, tgl\_pesan, tgl\_mulai, durasi, metode\_ambil (ambil\_di\_kantor atau diantar, hanya untuk lepas kunci), jam\_serah, lokasi\_jemput (kosong bila ambil di kantor), tujuan, domisili, catatan, setuju\_syarat, total\_biaya, status, status\_sebelumnya | FK pelanggan, tipe, kendaraan, admin |
| pembayaran | id\_pemesanan, jenis (sewa, tambahan, refund), metode, jumlah, order\_id\_midtrans, tgl\_bayar, status | banyak per pemesanan |
| pengembalian | tgl\_kembali, jam\_kembali, tarif\_denda\_per\_hari, hari\_terlambat, denda\_keterlambatan, odometer\_akhir, bbm\_akhir, biaya\_kekurangan\_bbm, kebersihan, catatan\_admin, disetujui\_penyewa | 1-1 dengan pemesanan |
| pengembalian\_kondisi | bagian, kondisi, biaya, keterangan | 8 baris per pengembalian |
| pengembalian\_foto | path\_foto | banyak per pengembalian |
| biaya\_tambahan | jenis, keterangan, nominal | banyak per pengembalian |
| jaminan | jenis, diterima, dikembalikan | banyak per pemesanan (lepas kunci) |
| review | rating, komentar, tgl\_review, tampil\_di\_katalog | 1-1 dengan pemesanan |
| perawatan | id\_kendaraan, id\_pemesanan\_asal, jenis\_servis, tingkat\_servis, tgl\_perawatan, km\_saat\_ini, bengkel, biaya\_bengkel, biaya\_ditagih\_ke\_penyewa, catatan, status, km\_berikutnya, tgl\_berikutnya | banyak per kendaraan; asal pesanan boleh kosong |
| detail\_perawatan | komponen, keterangan | banyak per perawatan |
| serah\_terima | id\_pemesanan, id\_admin, tgl\_serah, odometer\_awal, bbm\_awal, catatan\_kondisi, foto\_kondisi | 1-1 dengan pemesanan |
| tipe\_foto | id\_tipe, path\_foto, urutan | sampai 3 per tipe |
| pengaturan | kunci, nilai (jumlah sopir, alamat, nomor telepon, dan email kantor untuk halaman Kontak) | berdiri sendiri; diisi pengembang lewat database |

Catatan:

- `id_kendaraan` pada pemesanan kosong sampai admin menjalankan Serahkan unit, karena plat nomor ditetapkan saat itu.
- `id_admin` pada pemesanan berisi admin yang memproses (pembatalan, serah terima). Seluruh tindakan admin lainnya (tandai tagihan dibayar, kembalikan jaminan, ubah unit, servis, dan sebagainya) dicatat di `log_aktivitas` bersama waktunya, sehingga riwayat aktivitas di Detail Pesanan bisa menampilkan siapa pelakunya. `jenis_pesanan` diganti `jenis_sewa` (dengan sopir atau lepas kunci) karena pemesanan manual tidak ada.
- `akun.aktif` menentukan apakah akun boleh login. Admin yang berhenti dinonaktifkan, bukan dihapus.
- `token_akun` menyimpan token verifikasi email (12 jam) dan reset kata sandi (1 jam) dalam bentuk hash; token sekali pakai. Akun belum terverifikasi dihapus setelah 30 hari.
- `pemesanan.domisili` berisi kategori Malang Raya (Kota Malang, Kabupaten Malang, Kota Batu) atau luar Malang Raya, dihitung dari kota di profil saat memesan dan disalin ke pesanan; admin boleh menyesuaikannya saat Serahkan unit bila KTP berbeda (dicatat di log).
- `no_identitas` pada pelanggan tidak diisi saat registrasi, melainkan oleh admin saat Serahkan unit lepas kunci.
- `status_kepemilikan` pada detail\_kendaraan bernilai milik perusahaan atau titipan; untuk unit titipan `nama_pemilik` adalah pemilik yang mendapat bagian dari uang sewa. Tidak ada opsi leasing.
- Status pemesanan: menunggu\_pembayaran, dikonfirmasi, berjalan, selesai, pengajuan\_pembatalan, dibatalkan. `status_sebelumnya` dipakai saat pengajuan ditolak.
- `pembayaran` bisa punya beberapa baris per pemesanan: sewa lewat Midtrans, tagihan tambahan, dan refund. Status pembayaran sewa: menunggu, lunas, gagal (kedaluwarsa), dikembalikan.
- `serah_terima` menyimpan odometer awal, BBM awal, dan kondisi awal saat unit diserahkan; pesanan lanjutan mengambil odometer awal dari odometer akhir pesanan sebelumnya.
- `pengaturan` menyimpan jumlah sopir (kosong berarti tidak dibatasi) dan data kontak kantor.
- `jaminan.dikembalikan` hanya boleh terisi jika tagihan tambahan pemesanan itu nol atau sudah lunas.
- `review.tampil_di_katalog` bawaannya tidak tampil dan diatur admin.
- `km_berikutnya` dan `tgl_berikutnya` kosong sampai servis selesai. `biaya_bengkel` berisi estimasi saat servis dimulai, lalu diganti biaya akhir saat selesai.

## 9. Integrasi Eksternal

**Email (SMTP)**

- Dipakai untuk tautan verifikasi email (berlaku 12 jam) dan tautan reset kata sandi (berlaku 1 jam). Saat pengembangan memakai kotak surat uji (Mailtrap) yang tidak mengirim ke alamat asli; saat dideploy memakai SMTP sungguhan (mis. Gmail dengan kata sandi aplikasi, atau Brevo) yang diatur lewat berkas `.env`

**Laporan PDF**

- Dibuat dari data pesanan pada periode dan filter status terpilih
- Isi minimal: periode, tabel pesanan (tanggal mulai, pesanan, pelanggan, unit, status, sewa, tagihan tambahan), jumlah transaksi, total pendapatan

**Pembayaran (Midtrans, mode sandbox)**

- User membayar lewat Midtrans dengan QRIS atau virtual account; masa berlaku pembayaran paling lama 1 jam. Sistem membuat transaksi dan menyimpan `order_id_midtrans`
- Notifikasi (webhook) pembayaran berhasil mengubah status pesanan menjadi Dikonfirmasi; tidak ada verifikasi pembayaran manual oleh admin
- Jika lewat 1 jam tanpa pembayaran, pembayaran berstatus gagal dan pesanan otomatis dibatalkan. Pembatalan otomatis dipicu notifikasi kedaluwarsa dari Midtrans dan tugas terjadwal sebagai cadangan
- Pembayaran lunas sebesar biaya sewa, tanpa DP
- Refund pembayaran QRIS diproses lewat Midtrans setelah admin menekan Setujui; refund virtual account ditransfer admin di luar sistem lalu ditandai “refund selesai”
- Tagihan tambahan setelah pengembalian dibayar di luar Midtrans (tunai atau transfer) dan ditandai admin
- Webhook Midtrans tidak bisa menjangkau `localhost`; saat demo aplikasi perlu berjalan di server yang bisa diakses internet atau memakai tunnel seperti ngrok

## 10. Non-Functional Requirements

- **Performa:** halaman katalog load < 3 detik pada koneksi 4G
- **Konsistensi data:** perubahan status unit, pemesanan, pembayaran, dan servis dilakukan dalam satu transaksi database; cek ketersediaan dan pembuatan pesanan atomik (termasuk penahanan unit selama menunggu pembayaran), dan unit diverifikasi ulang saat Serahkan unit supaya tidak tersewa ganda
- **Keamanan:** kata sandi di-hash, halaman admin dilindungi role-based access, akun nonaktif tidak bisa login, validasi input di server, notifikasi Midtrans diverifikasi tanda tangannya, token verifikasi dan reset disimpan dalam bentuk hash dan hanya sekali pakai, serta percobaan login, lupa kata sandi, dan registrasi dibatasi per menit dan per jam, masa login berupa batas tidak aktif (user 60 menit, admin 30 menit), dan aplikasi menolak berjalan di production dengan konfigurasi tidak aman (debug aktif, email log, gateway palsu)
- **Privasi:** no\_identitas pelanggan dan detail internal kendaraan (rangka, BPKB, STNK) hanya diakses admin; respons untuk user disaring di server sehingga tidak memuat kolom internal
- **Responsif:** nyaman dipakai di HP
- **Auditability:** pemesanan, pembayaran, pengembalian, dan servis tidak dihapus, hanya diubah statusnya (tipe dan unit memakai tempat sampah, dan unit yang punya riwayat tidak pernah dibuang permanen); setiap tindakan admin dicatat di log aktivitas

## 11. Success Metrics

| Metrik | Target |
| --- | --- |
| Waktu dari buka katalog sampai pembayaran berhasil | < 5 menit |
| Bentrok jadwal (unit kurang saat serah terima) | 0 kejadian |
| Tagihan tambahan hitungan sistem dibanding hitungan manual | Selisih 0 |
| Tindakan admin yang tercatat pelakunya | 100% |
| Waktu admin menyusun laporan transaksi | Dari manual menjadi < 1 menit |
| Unit berstatus “perlu servis” yang belum ditangani | Dipantau sejak bulan pertama |
| Pesanan dibuat dibanding yang diajukan batal atau kedaluwarsa | Dipantau sejak bulan pertama |

## 12. Alur Utama

**Registrasi:** Guest mengisi nama, email, WhatsApp, domisili (provinsi dan kota), dan kata sandi (minimal 8 karakter dan ada angka). Sistem cek email belum dipakai, menyimpan akun belum terverifikasi, mengirim tautan verifikasi (12 jam), dan menampilkan “Cek email Anda”. Setelah user mengeklik tautan, email terverifikasi dan user diarahkan ke Login. Akun yang tidak diverifikasi dihapus setelah 30 hari.

**Login:** User dan admin login lewat satu halaman dengan email dan kata sandi. Sistem memeriksa akun valid dan aktif, lalu mengarahkan ke beranda sesuai peran. Akun belum terverifikasi tidak bisa login. User yang datang dari tombol Pesan kembali ke Katalog (isian tanggal dan durasi kosong); selain itu ke Home, dan admin ke Dashboard. Masa login user 3 hari dan admin 8 jam; Logout selalu ke Home. Akun admin tidak bisa didaftarkan dari aplikasi; pengembang membuatnya lewat database dan menonaktifkannya saat admin berhenti.


**Lupa kata sandi:** User memasukkan email. Jika terdaftar sebagai akun email, tautan reset (1 jam) dikirim; jika tidak terdaftar tampil “Email tidak terdaftar”. Setelah kata sandi baru disimpan, email ikut terverifikasi.

**Pemesanan:** User login, lalu mengisi tanggal mulai (paling cepat besok dan paling jauh 1 bulan ke depan) dan durasi (1 sampai 30 hari). Sistem menampilkan tipe yang tersedia beserta sisa unit, lalu user memilih tipe. Jika user masih punya tagihan belum lunas atau pesanan menunggu pembayaran, pemesanan ditolak. User mengisi form (jenis sewa, jam, catatan; lepas kunci: transmisi, cara terima unit (ambil di kantor atau diantar, lokasi jemput hanya bila diantar), dan persetujuan syarat jaminan; dengan sopir: lokasi jemput dan tujuan berupa teks bebas) dan mencentang syarat dan ketentuan. Sistem menghitung tanggal kembali dan ringkasan biaya sesuai jenis sewa. Dalam satu database transaction sistem mengunci tipe dan mengecek unit tersedia (pesanan yang menunggu pembayaran ikut dihitung, dan untuk paket sopir juga jumlah sopir); jika tidak ada tampil “mobil sudah dipesan pada tanggal tersebut”. Jika ada, pesanan dibuat berstatus “menunggu pembayaran”, unit ditahan, dan user membayar lunas lewat Midtrans (QRIS atau virtual account) dalam 1 jam. Setelah notifikasi pembayaran berhasil, status menjadi “dikonfirmasi”, tanggal terblokir, dan nota tampil. Jika lewat 1 jam tanpa pembayaran, pembayaran gagal, pesanan otomatis “dibatalkan”, dan unit dilepas.

**Pembatalan:** User buka Detail Pesanan. Pesanan “menunggu pembayaran” dibatalkan langsung setelah konfirmasi. Pesanan “dikonfirmasi” diajukan paling lambat H-1 sebelum tanggal mulai: user mengisi formulir (alasan; untuk virtual account bank, nomor rekening, dan nama rekening), status menjadi “pengajuan pembatalan”, dan tanggal tetap terblokir. Admin menyetujui (dibatalkan, unit dilepas, pembayaran dikembalikan; QRIS lewat Midtrans, virtual account ditransfer admin lalu ditandai “refund selesai”) atau menolak (kembali ke status sebelumnya). Admin tidak bisa membatalkan pesanan sendiri.

**Serah terima:** Admin buka Detail Pesanan berstatus “dikonfirmasi”, klik Serahkan unit, memilih plat dari daftar unit kosong yang disaring sistem, lalu (khusus lepas kunci) mencatat jaminan, no. identitas, odometer, dan BBM awal, serta kondisi awal (foto opsional). Status menjadi “berjalan” dan unit “disewa”. Bukti booking jaminan e diverifikasi admin sendiri ke sumbernya.

**Pengembalian mobil:** Admin buka Detail Pesanan berstatus “berjalan”, klik Selesaikan pesanan, lalu mengisi form pengembalian, pemeriksaan kondisi, biaya tambahan, jaminan, dan konfirmasi. Sistem menghitung tagihan tambahan, menyimpan hasil, mengubah status pesanan “selesai”, dan unit “tersedia” (atau “dalam servis” jika perlu servis). Jika ada tagihan tambahan yang belum dibayar, jaminan fisik tetap ditahan. Tagihan tampil ke penyewa; setelah pelunasan admin menandainya dibayar lalu menekan Kembalikan jaminan.

**Perpanjangan sewa:** Selama pesanan lama masih berjalan, pelanggan memesan lagi dengan tanggal mulai sehari setelah tanggal kembali pesanan lama dan membayar lewat Midtrans seperti biasa. Pada hari terakhir pesanan lama, admin menyelesaikan pesanan itu lewat form pengembalian dan pemeriksaan. Jika ada tagihan tambahan, tagihan itu harus lunas dulu. Jaminan ditandai dikembalikan hanya sebagai pencatatan lalu dicatat ulang di pesanan baru, dan admin menyerahkan unit pesanan baru dengan plat yang sama, dengan odometer awal diambil dari odometer akhir pesanan lama. Jika pesanan baru tidak dibayar dalam 1 jam, pesanan itu otomatis batal dan unit tetap harus dikembalikan pada tanggal kembali pesanan lama.

**Servis:** Admin buka halaman Servis. Catat: pilih unit (ditunda jika sedang disewa), isi data servis, sistem simpan, unit “dalam perawatan”. Selesai: pilih servis berlangsung, input komponen, biaya, dan odometer; sistem hitung selisih biaya dan jadwal berikutnya, unit “tersedia”. Batal: servis “dibatalkan”, unit “tersedia”. Admin juga mengatur interval dan memantau pengingat berkala.

**Laporan:** Admin buka halaman Laporan yang langsung menampilkan 7 hari terakhir, lalu bisa memilih periode dan filter status. Jika kosong tampil “tidak ada data pada periode ini”; jika ada, sistem menghitung total pendapatan, jumlah transaksi, dan rincian per status, lalu menyediakan unduhan PDF.

## 13. Timeline Usulan

| Fase | Cakupan |
| --- | --- |
| 1 | Autentikasi (email), registrasi dengan verifikasi email, role admin/user/guest, akun admin lewat seeder, reset kata sandi (SMTP), desain database (20 tabel), tabel pengaturan |
| 2 | Modul kendaraan: CRUD tipe dan unit + detail kendaraan + katalog publik |
| 3 | Modul pemesanan: form pesanan, cek ketersediaan, penahanan unit 1 jam dan pembatalan otomatis, pembayaran Midtrans, nota, Detail Pesanan, riwayat |
| 4 | Serah terima, pengembalian dan pemeriksaan unit, tagihan tambahan, penahanan dan pengembalian jaminan, pembatalan (formulir + proses admin + refund) |
| 5 | Servis berkala (catat, selesai, batal, interval) + status servis, review, profil |
| 6 | Laporan transaksi + PDF, dashboard admin, log aktivitas pada Detail Pesanan |
| 7 | Testing, perbaikan, deploy |

## 14. Open Questions & Risiko

**Open Questions**

- Tempat deploy belum ditentukan dan diputuskan di fase 7. Minimal aplikasi harus bisa menerima webhook Midtrans saat demo (server publik atau tunnel)
- Data awal dari pusat belum ada: jumlah sopir (kosong berarti tidak dibatasi), jumlah unit per tipe, daftar tarif lengkap, data kontak kantor resmi (telepon dan email), dan rekening resmi perusahaan
- Nominal biaya antar di luar batas Tlogomas belum ditetapkan (dicatat admin sebagai biaya tambahan saat pengembalian)
- Kebijakan kecelakaan, mogok atau derek, dan asuransi unit mengikuti pusat dan belum dijawab

**Risiko**

- Dua user memesan tipe yang sama bersamaan sehingga unit kurang (mitigasi: cek jumlah unit dan buat pesanan dalam satu transaksi, pesanan menunggu pembayaran ikut menahan unit, lalu verifikasi ulang saat Serahkan unit)
- Unit ditahan oleh pesanan yang akhirnya tidak dibayar sehingga ketersediaan tertutup sesaat (mitigasi: batas 1 jam, pelepasan otomatis saat kedaluwarsa lewat notifikasi Midtrans dan tugas terjadwal)
- Notifikasi Midtrans terlambat atau gagal sehingga status pesanan tidak berubah (mitigasi: verifikasi tanda tangan dan cek ulang status transaksi)
- Refund virtual account ditransfer di luar sistem tidak terlacak di sistem (mitigasi: admin wajib menekan Setujui dan status pembayaran “dikembalikan” setelah refund)
- Beberapa admin memproses pesanan yang sama bersamaan (mitigasi: catat id\_admin dan log aktivitas, dan tolak aksi jika status sudah berubah)
- Admin yang sudah berhenti masih bisa login (mitigasi: pengembang menonaktifkan akun, dan status aktif diperiksa setiap login)
- Pesanan diajukan pembatalan tetapi admin lambat memproses, sehingga tanggal tertahan (mitigasi: daftar pengajuan pembatalan di dashboard admin)
- Tagihan tambahan pada sewa dengan sopir tidak punya jaminan yang ditahan (mitigasi: banner tagihan di Detail Pesanan dan pemblokiran pesanan baru sampai lunas)
- Email verifikasi tidak sampai (mitigasi: tombol Kirim ulang maksimal 3 kali per jam dan pengingat memeriksa folder spam)
- Bukti booking transportasi atau hotel dipalsukan saat serah terima (mitigasi: prosedur verifikasi admin ke sumbernya, jaminan lain tetap ditahan, hasil dicatat di catatan admin)
- Pembayaran masuk setelah batas 1 jam saat jatah tipe sudah terpakai (mitigasi: pengecekan ulang di dalam transaksi; pembayaran dibatalkan otomatis dan direfund penuh)
- Perpanjangan manual salah urutan, misalnya pesanan baru diserahkan sebelum tagihan pesanan lama lunas (mitigasi: aturan tagihan lunas dulu, dan odometer awal diisi otomatis dari pesanan sebelumnya)
- Servis selesai tanpa odometer yang benar sehingga status pengingat tidak sinkron (mitigasi: odometer wajib dan tidak boleh lebih kecil dari sebelumnya)
- Detail internal kendaraan bocor ke user (mitigasi: tabel terpisah dan pembatasan di level query)
- Pesanan paket dengan sopir melebihi jumlah sopir yang tersedia (mitigasi: batas jumlah sopir di pengaturan dan cek ketersediaan sopir saat memesan)
- Unit dibawa keluar kota tanpa ditandai Tidak tersedia sehingga bisa dipesan user lain (mitigasi: aturan kerja admin menandai Tidak tersedia selama perjalanan, dan peringatan pesanan terdampak)
- Dokumen dan prototipe HTML tidak sinkron dengan flowchart V17 (mitigasi: perbarui V17 untuk detail yang baru ada di prototipe)
