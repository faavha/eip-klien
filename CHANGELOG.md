# Changelog

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/),
versi mengikuti [SemVer](https://semver.org/lang/id/).

## [1.0.0] - 2026-11-07

### Ditambahkan
- `EipSso` + route `auth.eip` / `auth.eip.callback`: login SSO OIDC
  (Authorization Code + PKCE, ADR 0018), verifikasi ID token lewat JWKS
  (iss, aud, nonce, sub, exp), ambil ulang JWKS saat kunci dirotasi,
  cadangan endpoint bila `/.well-known/` belum tersedia, logout terpusat.
- `Contracts\PenanganLogin`: akun lokal & penolakan tetap di aplikasi.
- `EipClient`: pegawai, unit kerja, aplikasi per pegawai, `/akses`,
  `/akses/pemegang` (baca saja, paginasi diikuti otomatis).
- `PeranEip`: peran + unit lingkup dari klaim token atau `/akses` (ADR 0017).
- `PengalihAplikasi`: isi tombol "Aplikasi" dengan cache dan daftar cadangan.
- `php artisan eip:banding-peran` + `Contracts\PeranLokal`.
- Dukungan `resolve_ip` untuk aplikasi satu server dengan EIP (hairpin NAT).
