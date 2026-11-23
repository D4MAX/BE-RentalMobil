<?php

$produksi = (bool) env('MIDTRANS_IS_PRODUCTION', false);

return [
    // palsu = tanpa jaringan, untuk pengembangan lokal dan Swagger; midtrans = Snap asli. APP_ENV=testing selalu palsu.
    'gateway' => env('PEMBAYARAN_GATEWAY', 'palsu'),

    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'client_key' => env('MIDTRANS_CLIENT_KEY'),
    'is_production' => $produksi,
    'snap_url' => $produksi
        ? 'https://app.midtrans.com/snap/v1/transactions'
        : 'https://app.sandbox.midtrans.com/snap/v1/transactions',
    'api_url' => $produksi ? 'https://api.midtrans.com' : 'https://api.sandbox.midtrans.com',
    'timeout' => 10, // detik
];
