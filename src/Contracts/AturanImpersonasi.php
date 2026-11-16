<?php

namespace Fmipa\EipKlien\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Bagian "Masuk sebagai" (ADR 0019) yang khas tiap aplikasi: siapa boleh
 * menyamar, siapa dilindungi, dan cara membaca nama/email akun lokal.
 *
 * Daftarkan di AppServiceProvider:
 *   $this->app->bind(AturanImpersonasi::class, \App\Auth\AturanImpersonasi::class);
 *
 * Paket menambahkan pemeriksaan yang sama untuk semua aplikasi: tidak boleh
 * diri sendiri, tidak berantai, dan peran pelaku dicek ulang ke EIP.
 */
interface AturanImpersonasi
{
    /** Pelaku memegang peran admin tertinggi aplikasi (salinan lokal). */
    public function bolehMenyamar(Authenticatable $pelaku): bool;

    /** Target tidak boleh disamar: admin tertinggi atau akun cadangan lokal. */
    public function dilindungi(Authenticatable $target): bool;

    /** Target aktif dan masih punya peran di aplikasi. */
    public function aktif(Authenticatable $target): bool;

    /** Email akun untuk cek ulang peran ke EIP; null bila tidak terhubung EIP. */
    public function email(Authenticatable $pengguna): ?string;

    public function nama(Authenticatable $pengguna): string;
}
