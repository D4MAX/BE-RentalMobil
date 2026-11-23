<?php

return [
    // Tautan di email mengarah ke aplikasi tampilan (frontend)
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    // Masa berlaku token login (menit), berlaku sebagai idle timeout: setiap permintaan terautentikasi yang berhasil memperpanjang
    // expires_at (hanya bila sisa waktu tinggal separuh, lihat PerpanjangToken). Diatur lewat TOKEN_ADMIN_MENIT dan TOKEN_USER_MENIT.
    'token_admin_menit' => (int) env('TOKEN_ADMIN_MENIT', 30),
    'token_user_menit' => (int) env('TOKEN_USER_MENIT', 60),

    // Masa berlaku tautan email (jam)
    'verifikasi_jam' => 12,
    // Akun user belum terverifikasi baru bisa ditimpa pendaftaran ulang (email sama) setelah sekian menit sejak dibuat.
    // Sebelum itu pendaftaran ulang ditolak, supaya orang lain tidak bisa memasang kata sandinya sendiri di akun yang baru dibuat.
    'daftar_ulang_tunggu_menit' => (int) env('DAFTAR_ULANG_TUNGGU_MENIT', 60),

    // Masa berlaku token reset kata sandi (menit)
    'reset_menit' => 60,

    // Akun admin awal (seeder). Kata sandi sementara, wajib diganti saat login pertama.
    'admin_email' => env('ADMIN_EMAIL', 'admin@rental.test'),
    'admin_password' => env('ADMIN_PASSWORD') ?: 'Admin12345',
    'admin_nama' => env('ADMIN_NAMA', 'Admin Utama'),

    // Aturan tanggal pemesanan
    'maks_hari_ke_depan' => 30,
    'maks_durasi' => 30,
    'batas_bayar_menit' => 60,

    // Jam serah lepas kunci (ambil di kantor atau diantar)
    'jam_serah_mulai' => '07:00',
    'jam_serah_akhir' => '21:00',

    // Kota yang dihitung Malang Raya (huruf kecil); selain itu luar_malang_raya
    'kota_malang_raya' => ['kota malang', 'kabupaten malang', 'kota batu'],

    // php artisan demo:reset: satu-satunya database yang boleh direset, dan folder upload milik aplikasi ini (per disk).
    // Nama lain ditolak. Diatur lewat DEMO_RESET_DATABASE; rentalmobil dan rentalmobil_test (proyek lain) selalu ditolak, apa pun isinya.
    'demo_reset_database' => env('DEMO_RESET_DATABASE', 'rental_mobil'),
    'demo_reset_terlarang' => ['rentalmobil', 'rentalmobil_test'],
    'folder_upload' => [
        'public' => ['tipe', 'profil'],
        'local' => ['jaminan', 'serah-terima', 'pengembalian'],
    ],

    // Tempat sampah tipe dan unit: lama (hari) sebelum dibuang permanen oleh `php artisan sampah:bersihkan` (terjadwal harian)
    'hari_sampah' => (int) env('HARI_SAMPAH', 30),

    // Interval servis bawaan (km); nilai aktif ada di tabel pengaturan
    'interval_km' => 5000,
    'interval_bulan' => 6,
];
