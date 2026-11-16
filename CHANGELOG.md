# Changelog

Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/),
versi mengikuti [SemVer](https://semver.org/lang/id/).

## [1.1.1] - 2026-11-16

### Diperbaiki
- Migrasi `impersonasi_log` selalu didaftarkan (tidak bergantung
  `impersonasi.aktif`): `migrate` dengan cache config lama sempat
  melewatkannya saat fitur pertama dinyalakan.
- Form alasan "Masuk sebagai" tampil di `<dialog>` di tengah layar, bukan
  popup absolut yang terpotong tabel bergulir; nama target ditampilkan; isi
  slot boleh menggantikan label (mis. ikon + teks).
- Tabel riwayat memberi `data-label` per sel (tabel bertumpuk di layar kecil).

## [1.1.0] - 2026-11-16

### Ditambahkan
- "Masuk sebagai" (ADR 0019), mati bawaan (`eip-klien.impersonasi.aktif`):
  `Impersonasi`, `Contracts\AturanImpersonasi`, route
  `eip.impersonasi.mulai` / `eip.impersonasi.berhenti`, middleware
  `JagaImpersonasi` (batas 25 menit, route terblokir, logout = keluar dari
  akun asli), migrasi + model `impersonasi_log` (tidak bisa diubah/dihapus),
  komponen `banner-menyamar`, `tombol-masuk-sebagai`, `riwayat-masuk-sebagai`.
- Peran pelaku dicek ulang ke EIP `/akses` tanpa cache saat mulai.
- Ketergantungan `illuminate/auth`, `illuminate/database`, `illuminate/view`
  (sudah ada di setiap aplikasi Laravel).

## [1.0.1] - 2026-11-07

### Diubah
- Lisensi menjadi MIT (sebelumnya `proprietary`); berkas `LICENSE` ditambahkan.
- README: repo publik, tidak perlu token GitHub.

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
