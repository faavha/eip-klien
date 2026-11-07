<?php

namespace Fmipa\EipKlien;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Isi tombol "Aplikasi" di bilah atas, dari EIP Core (`/pegawai/{uid}/aplikasi`).
 * Disimpan per akun beberapa menit. Bila EIP tak terjangkau, tampil daftar
 * minimal (EIP + `eip-klien.pengalih.minimal`) agar halaman tetap jalan.
 */
class PengalihAplikasi
{
    public function __construct(private readonly EipClient $eip) {}

    /** @return list<array<string, mixed>> */
    public function untuk(Authenticatable $user): array
    {
        $kunci = 'pengalih-aplikasi:'.$user->getAuthIdentifier();
        if (is_array($tersimpan = Cache::get($kunci))) {
            return $tersimpan;
        }

        try {
            $daftar = $this->eip->aplikasiByEmail((string) $user->email);
        } catch (Throwable) {
            return $this->minimal();
        }

        $daftar = array_values(array_filter(
            $daftar,
            fn ($app) => is_array($app) && filled($app['kode'] ?? null) && filled($app['nama'] ?? null) && filled($app['url'] ?? null),
        ));
        $daftar = array_map(fn (array $app) => $app + ['deskripsi' => '', 'warna' => '#64748b', 'ikon' => ''], $daftar);
        $daftar = $daftar ?: $this->minimal();
        Cache::put($kunci, $daftar, now()->addMinutes((int) config('eip-klien.pengalih.cache_menit', 15)));

        return $daftar;
    }

    /** @return list<array<string, mixed>> */
    public function minimal(): array
    {
        $eip = [
            'kode' => 'eip', 'nama' => 'EIP Core', 'deskripsi' => 'Pegawai, unit, akses',
            'url' => $this->eip->baseUrl() ?: 'https://eip.mipa.uns.ac.id', 'warna' => '#2f6690',
            'ikon' => 'M12 3l9 4.5-9 4.5-9-4.5L12 3zM3 12l9 4.5 9-4.5M3 16.5l9 4.5 9-4.5',
        ];

        $lain = array_map(
            fn (array $app) => $app + ['url' => config('app.url'), 'deskripsi' => '', 'warna' => '#64748b', 'ikon' => ''],
            (array) config('eip-klien.pengalih.minimal', []),
        );

        return [$eip, ...array_values($lain)];
    }
}
