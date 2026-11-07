# eip-klien

Paket Laravel bersama untuk semua aplikasi FMIPA yang memakai **EIP Core**:

| Bagian | Kelas | Isi |
|---|---|---|
| Login SSO | `EipSso`, route `auth.eip` + `auth.eip.callback` | OIDC + PKCE ke EIP (ADR 0018), verifikasi ID token via JWKS |
| API EIP | `EipClient` | pegawai, unit kerja, `/akses`, `/akses/pemegang`, aplikasi per pegawai (baca saja) |
| Peran | `PeranEip` | peran + unit lingkup dari klaim token atau `/akses` (ADR 0017) |
| Tombol "Aplikasi" | `PengalihAplikasi` | daftar aplikasi pegawai dari EIP, cache, cadangan bila EIP mati |
| Peralihan peran | `php artisan eip:banding-peran` | bandingkan peran lokal vs EIP (baca saja) |

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
