<?php

namespace Fmipa\EipKlien\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bagian login yang khas tiap aplikasi: mencari/membuat akun lokal dari klaim
 * ID token EIP, menyalin peran, dan menolak bila belum berwenang.
 *
 * Daftarkan di AppServiceProvider:
 *   $this->app->bind(PenanganLogin::class, \App\Auth\LoginEip::class);
 */
interface PenanganLogin
{
    /**
     * Klaim sudah terverifikasi (`sub`, `email`, `name`, `peran`, `peran_unit`).
     *
     * Kembalikan akun untuk dimasukkan (paket yang memanggil Auth::login,
     * regenerasi sesi, dan menandai `login_via_eip`), ATAU response sendiri
     * (mis. redirect ke halaman "belum berwenang").
     *
     * @param  array<string, mixed>  $klaim
     */
    public function masuk(array $klaim, Request $request): Authenticatable|Response;

    /** Tampilkan pesan kegagalan login (biasanya kembali ke halaman login). */
    public function gagal(string $pesan): Response;
}
