<?php

/*
 * Pengaturan klien EIP Core. Terbitkan ke aplikasi bila nama variabel .env
 * aplikasi berbeda:  php artisan vendor:publish --tag=eip-klien-config
 */
return [
    // Alamat EIP Core (API /api/v1 + penerbit OIDC).
    'base_url' => env('EIP_BASE_URL', 'https://eip.mipa.uns.ac.id'),

    // Penerbit (iss) ID token. Kosong = sama dengan base_url.
    'issuer' => env('EIP_ISSUER'),

    // Token service client (ability master:read), dibuat di EIP:
    //   php artisan service-client:create <kode> "<Nama>" --abilities=master:read
    'api_token' => env('EIP_API_TOKEN'),

    // Klien OIDC aplikasi ini di EIP (ADR 0018).
    'client_id' => env('EIP_OIDC_CLIENT_ID'),
    'client_secret' => env('EIP_OIDC_CLIENT_SECRET'),

    // Kode aplikasi ini di EIP (ADR 0017), mis. `strap`, `logistik`, `sigap`.
    'kode_aplikasi' => env('EIP_KODE_APLIKASI'),

    // Aplikasi satu server dengan EIP: domain EIP diarahkan paksa ke IP ini
    // (hairpin NAT — server tidak bisa memanggil IP publiknya sendiri).
    // Kosongkan bila EIP berada di server lain.
    'resolve_ip' => env('EIP_RESOLVE_IP'),

    'timeout' => 15,

    'sso' => [
        // Daftarkan route /auth/eip dan /auth/eip/callback dari paket.
        'routes' => true,
        'middleware' => ['web', 'guest'],
        'scope' => 'openid profile email peran',
        // Nama route tujuan setelah login berhasil (bila tak ada "intended").
        'setelah_login' => 'dashboard',
        // Nama route tujuan setelah logout terpusat di EIP.
        'setelah_logout' => 'login',
        // "Ingat saya" saat Auth::login.
        'ingat' => false,
    ],

    // Tombol "Aplikasi" (pengalih aplikasi) — daftar dari EIP per pegawai.
    'pengalih' => [
        'cache_menit' => 15,
        // Daftar cadangan bila EIP tak terjangkau (EIP Core selalu ditambahkan).
        'minimal' => [],
    ],
];
