<?php

return [
    // Denda keterlambatan: persen dari harga sewa per hari (100 = sama dengan harga sewa per hari)
    'denda_persen_per_hari' => (int) env('RENTAL_DENDA_PERSEN', 100),

    // Interval servis berkala
    'interval_km' => (int) env('RENTAL_INTERVAL_KM', 10000),
    'interval_bulan' => (int) env('RENTAL_INTERVAL_BULAN', 6),

    // Nomor WhatsApp admin (format internasional, tanpa tanda +)
    'wa_admin' => env('RENTAL_WA_ADMIN', '6281234567890'),
];