# eip-klien

Paket Laravel bersama untuk semua aplikasi FMIPA yang memakai **EIP Core**:

| Bagian | Kelas | Isi |
|---|---|---|
| Login SSO | `EipSso`, route `auth.eip` + `auth.eip.callback` | OIDC + PKCE ke EIP (ADR 0018), verifikasi ID token via JWKS |
| API EIP | `EipClient` | pegawai, unit kerja, `/akses`, `/akses/pemegang`, aplikasi per pegawai (baca saja) |
| Peran | `PeranEip` | peran + unit lingkup dari klaim token atau `/akses` (ADR 0017) |
| Tombol "Aplikasi" | `PengalihAplikasi` | daftar aplikasi pegawai dari EIP, cache, cadangan bila EIP mati |
| Peralihan peran | `php artisan eip:banding-peran` | bandingkan peran lokal vs EIP (baca saja) |
| Masuk sebagai | `Impersonasi`, komponen `<x-eip-klien::…>` | impersonate admin seragam (ADR 0019), mati bawaan |
| Endpoint status | route `eip-klien.status`, `PengumpulStatus` | kondisi aplikasi untuk Peta Sistem EIP (versi, cron, debug, migrasi, error) |

Yang **tetap di aplikasi**: membuat/mencocokkan akun `User`, menyalin peran ke
model lokal, dan pesan penolakan — lewat `Contracts\PenanganLogin`.

## Memasang

```jsonc
// composer.json aplikasi
"repositories": [{ "type": "vcs", "url": "https://github.com/faavha/eip-klien" }],
"require": { "fmipa/eip-klien": "^1.0" }
```

lalu `composer update fmipa/eip-klien`. Repo publik: server tidak perlu token GitHub.

### `.env`

```
EIP_BASE_URL=https://eip.mipa.uns.ac.id
EIP_API_TOKEN=...            # service client ability master:read
EIP_OIDC_CLIENT_ID=...       # php artisan oidc:client <kode> https://<app>/auth/eip/callback
EIP_OIDC_CLIENT_SECRET=...
EIP_KODE_APLIKASI=sigap
EIP_RESOLVE_IP=127.0.0.1     # hanya bila satu server dengan EIP
```

Bila aplikasi sudah memakai nama variabel lain, terbitkan config dan
sesuaikan: `php artisan vendor:publish --tag=eip-klien-config`.

### Penangan login

```php
// app/Auth/LoginEip.php
class LoginEip implements \Fmipa\EipKlien\Contracts\PenanganLogin
{
    public function masuk(array $klaim, Request $request): Authenticatable|Response
    {
        $peran = PeranEip::dariKlaim($klaim);        // peran + unit dari token
        if ($peran->kosong()) {
            return $this->gagal('Anda belum punya peran di aplikasi ini.');
        }
        $user = User::updateOrCreate(['eip_uid' => $klaim['sub']], [...]);

        return $user;   // paket: Auth::login, regenerasi sesi, login_via_eip, redirect
    }

    public function gagal(string $pesan): Response
    {
        return redirect()->route('login')->with('error', $pesan);
    }
}

// AppServiceProvider::register()
$this->app->bind(PenanganLogin::class, LoginEip::class);
```

Klaim berisi `sub` (uid pegawai), `email` (huruf kecil), `name`, `peran`,
`peran_unit`. Tombol login cukup menuju `route('auth.eip')`; logout terpusat:
`app(EipSso::class)->alamatLogout()`.

### Tombol "Aplikasi"

```php
$aplikasi = app(\Fmipa\EipKlien\PengalihAplikasi::class)->untuk(auth()->user());
```

Daftar cadangan diisi di `eip-klien.pengalih.minimal` (EIP Core selalu ada).

### Peralihan peran ke EIP

Daftarkan `Contracts\PeranLokal` (kode peran + email => peran lokal), lalu
jalankan `php artisan eip:banding-peran` di server sebelum login dialihkan.

### "Masuk sebagai" (ADR 0019)

Admin masuk sebagai pengguna lain paling lama 25 menit, dengan alasan
(bawaan "Testing") dan jejak di tabel `impersonasi_log`. Paket menolak: diri
sendiri, berantai, target yang dilindungi/nonaktif, dan pelaku yang peran
adminnya tidak ada di EIP (dicek ulang tanpa cache; EIP mati = ditolak).

1. Ikat aturan aplikasi:
   ```php
   $this->app->bind(\Fmipa\EipKlien\Contracts\AturanImpersonasi::class, \App\Auth\AturanImpersonasi::class);
   ```
2. Nyalakan di `config/eip-klien.php` (blok `impersonasi`): `aktif`,
   `peran_pelaku`, `beranda`, `route_keluar`, `diblokir` (tambahkan route
   pengguna/pengaturan aplikasi), `flash`. Lalu `php artisan migrate`.
3. Pasang komponen:
   ```blade
   <x-eip-klien::banner-menyamar />                          {{-- sekali, di layout --}}
   <x-eip-klien::tombol-masuk-sebagai :pengguna="$u" />       {{-- per baris pengguna --}}
   <x-eip-klien::riwayat-masuk-sebagai class="tabel" />       {{-- halaman admin --}}
   ```

Middleware `JagaImpersonasi` dipasang otomatis di grup `web`: kembali ke akun
asli saat waktu habis, menolak route `diblokir`, dan saat route logout
dipanggil menghentikan penyamaran dulu sehingga akun asli ikut keluar.
Penanda jejak data aplikasi: `app(Impersonasi::class)->pelakuId()`.
Tampilan bisa diubah: `php artisan vendor:publish --tag=eip-klien-views`.

## Endpoint status

`GET /eip-klien/status` (route `eip-klien.status`) melaporkan kondisi aplikasi
ke halaman **Peta Sistem** di EIP. Aktif bawaan; matikan dengan
`eip-klien.status.aktif = false`. Tanpa sesi/CSRF, di-throttle
(`status.middleware`, bawaan `throttle:30,1`).

**Autentikasi tanpa secret baru.** EIP mengirim `Authorization: Bearer <JWT>`
yang ditandatangani kunci OIDC EIP (kunci yang sama dengan ID token). Paket
memeriksanya dengan JWKS EIP (cache + `EIP_RESOLVE_IP` sama dengan SSO):
hanya RS256; `iss` = issuer EIP; `aud` = `EIP_KODE_APLIKASI`; `sub` =
`peta-sistem`; umur (`exp − iat`) paling lama 5 menit, toleransi jam 30 detik;
`jti` wajib dan sekali pakai. Gagal → `401 {"pesan": "Tidak diizinkan."}`.
Jadi `EIP_KODE_APLIKASI` **wajib** terisi dan sama dengan kode aplikasi di EIP.

Isi respons (tanpa secret, isi log, atau path absolut):

| Kunci | Isi |
|---|---|
| `kode`, `paket_versi`, `laravel`, `php` | kode aplikasi, versi eip-klien, Laravel, PHP |
| `app_env`, `app_debug` | lingkungan dan APP_DEBUG (bool) |
| `commit`, `commit_waktu` | dari berkas `REVISION` (baris 1 hash, baris 2 opsional waktu ISO; bila tak ada, mtime berkas), atau dibaca langsung dari `.git` (tanpa menjalankan git); null bila keduanya tak ada |
| `cron_detak_terakhir` | waktu terakhir `schedule:run` berjalan (ISO 8601) atau null |
| `migrasi_tertunda` | jumlah migrasi (aplikasi + paket) yang belum dijalankan |
| `error_24jam` | baris log ERROR/CRITICAL/ALERT/EMERGENCY 24 jam terakhir dari `laravel.log` dan `laravel-YYYY-MM-DD.log` hari ini/kemarin (hanya ±2 MB terakhir tiap berkas) |
| `antrian_gagal` | jumlah baris `failed_jobs` (null bila tabel tak ada) |
| `waktu_server` | jam server |

Bagian yang gagal dibaca bernilai `null`.

**Detak cron.** Paket menambah tugas ringan `eip-klien:detak` tiap menit ke
scheduler aplikasi (menulis waktu ke cache `eip-klien:detak`). Tidak perlu
mengubah `routes/console.php`; aplikasi tanpa cron cukup melaporkan null.
Matikan dengan `status.detak = false`. Cache aplikasi harus bersama antara
CLI dan web (driver `file`/`database`/`redis`, bukan `array`).

**Aplikasi tanpa `.git` di server** (dikirim lewat `git archive`): skrip
deploy menulis `REVISION` di root aplikasi, mis.
`{ git rev-parse HEAD; date -Iseconds; } | ssh … "cat > <root>/REVISION"`.
Masukkan `REVISION` ke `.gitignore`.

## Pengaturan lain (`config/eip-klien.php`)

`sso.routes` (matikan bila aplikasi mendaftarkan route sendiri),
`sso.setelah_login` (nama route, bawaan `dashboard`), `sso.setelah_logout`
(bawaan `login`), `sso.ingat`, `pengalih.cache_menit`.

## Mengembangkan

```
composer install && vendor/bin/phpunit
```

Rilis: perbarui `CHANGELOG.md`, `git tag vX.Y.Z && git push --tags`, lalu
`composer update fmipa/eip-klien` di tiap aplikasi.

## Lisensi

MIT — lihat [LICENSE](LICENSE).
