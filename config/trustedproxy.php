<?php

return [
    // Proxy yang dipercaya (nginx, Cloudflare, ngrok, ...), dibaca middleware TrustProxies bawaan Laravel saat permintaan masuk.
    // Kosong = tidak percaya proxy mana pun: $request->ip() adalah alamat yang tersambung langsung (perilaku lokal).
    // Di belakang proxy isi dengan IP proxy dipisah koma, mis. "10.0.0.5,10.0.0.6"; bila tidak, semua pengguna terbaca
    // berasal dari IP proxy dan berbagi satu jatah batas laju (login, lupa kata sandi, daftar).
    // "*" mempercayai siapa pun yang tersambung langsung: pakai hanya bila server hanya bisa dijangkau lewat proxy.
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
