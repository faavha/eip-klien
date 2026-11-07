<?php

namespace Fmipa\EipKlien\Contracts;

/**
 * Peran yang masih tersimpan di aplikasi (sebelum dialihkan ke EIP). Dipakai
 * `php artisan eip:banding-peran` untuk melihat siapa yang akan kehilangan
 * atau mendapat akses bila peran dibaca dari EIP.
 */
interface PeranLokal
{
    /** @return list<string> kode peran aplikasi yang dibandingkan */
    public function kodePeran(): array;

    /**
     * Akun aktif beserta peran lokalnya.
     *
     * @return iterable<string, list<string>> email => kode peran lokal
     */
    public function akun(): iterable;
}
