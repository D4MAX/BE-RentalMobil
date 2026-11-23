<?php

// Hanya dipakai untuk jalur API. Autentikasi memakai Bearer token (bukan cookie), jadi supports_credentials=false.
// Swagger UI (/docs) satu origin dengan API sehingga tidak terpengaruh.
return [
    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],

    // CORS_ALLOWED_ORIGINS: alamat frontend dipisah koma (tanpa garis miring di akhir). Kosong = FRONTEND_URL.
    'allowed_origins' => array_values(array_filter(array_map(
        fn (string $o) => rtrim(trim($o), '/'),
        explode(',', env('CORS_ALLOWED_ORIGINS') ?: env('FRONTEND_URL', 'http://localhost:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    // Retry-After (hitung mundur saat 429) dan Content-Disposition (nama berkas nota dan laporan PDF) perlu terbaca frontend lintas origin
    'exposed_headers' => ['Retry-After', 'Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => false,
];
