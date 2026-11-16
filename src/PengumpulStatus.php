<?php

namespace Fmipa\EipKlien;

use Composer\InstalledVersions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Mengumpulkan kondisi aplikasi untuk endpoint status (Peta Sistem EIP):
 * versi, commit tayang, detak cron, migrasi tertunda, jumlah error log, dan
 * antrean gagal. Tidak pernah memuat secret, isi log, atau path absolut.
 * Setiap bagian yang gagal dibaca bernilai null, bukan membuat galat.
 */
class PengumpulStatus
{
    /** Kunci cache detak scheduler (ditulis tiap menit oleh schedule:run). */
    public const KUNCI_DETAK = 'eip-klien:detak';

    /** Batas bacaan dari ekor tiap berkas log (byte). */
    public const BATAS_LOG = 2 * 1024 * 1024;

    public const POLA_ERROR = '/^\[(\d{4}-\d{2}-\d{2}[ T][\d:]+)[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY):/';

    /** @return array<string, mixed> */
    public function kumpulkan(): array
    {
        $commit = $this->commit();

        return [
            'kode' => config('eip-klien.kode_aplikasi'),
            'paket_versi' => $this->versiPaket(),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'app_env' => (string) config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'commit' => $commit['hash'],
            'commit_waktu' => $commit['waktu'],
            'cron_detak_terakhir' => $this->detakTerakhir(),
            'migrasi_tertunda' => $this->migrasiTertunda(),
            'error_24jam' => $this->hitungError(),
            'antrian_gagal' => $this->antrianGagal(),
            'waktu_server' => now()->toIso8601String(),
        ];
    }

    public function versiPaket(): ?string
    {
        try {
            return InstalledVersions::isInstalled('fmipa/eip-klien')
                ? InstalledVersions::getPrettyVersion('fmipa/eip-klien')
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Commit yang tayang: berkas REVISION (ditulis skrip deploy) lebih
     * dulu, lalu `.git` dibaca langsung (tanpa menjalankan git).
     *
     * @return array{hash: ?string, waktu: ?string}
     */
    public function commit(): array
    {
        return $this->dariRevision(base_path('REVISION'))
            ?? $this->dariGit(base_path('.git'))
            ?? ['hash' => null, 'waktu' => null];
    }

    /** @return array{hash: string, waktu: ?string}|null */
    public function dariRevision(string $berkas): ?array
    {
        if (! is_file($berkas) || ! is_readable($berkas)) {
            return null;
        }
        $baris = preg_split('/\R/', trim((string) @file_get_contents($berkas)));
        $hash = strtolower(trim($baris[0] ?? ''));
        if (! $this->hashSah($hash)) {
            return null;
        }

        $waktu = null;
        if (filled($baris[1] ?? null)) {
            try {
                $waktu = Carbon::parse(trim($baris[1]))->toIso8601String();
            } catch (Throwable) {
                $waktu = null;
            }
        }
        $waktu ??= ($mtime = @filemtime($berkas)) ? Carbon::createFromTimestamp($mtime)->toIso8601String() : null;

        return ['hash' => $hash, 'waktu' => $waktu];
    }

    /** @return array{hash: string, waktu: ?string}|null */
    public function dariGit(string $git): ?array
    {
        // Worktree/submodule: `.git` berupa berkas berisi "gitdir: <path>".
        if (is_file($git)) {
            $isi = trim((string) @file_get_contents($git));
            if (! str_starts_with($isi, 'gitdir:')) {
                return null;
            }
            $tujuan = trim(substr($isi, 7));
            $git = str_starts_with($tujuan, '/') ? $tujuan : dirname($git).'/'.$tujuan;
        }
        if (! is_file($git.'/HEAD')) {
            return null;
        }

        $head = trim((string) @file_get_contents($git.'/HEAD'));
        if (! str_starts_with($head, 'ref:')) {
            // HEAD terlepas (detached): isinya langsung hash.
            return $this->hashSah(strtolower($head)) ? ['hash' => strtolower($head), 'waktu' => null] : null;
        }

        $ref = trim(substr($head, 4));
        if (str_contains($ref, '..')) {
            return null;
        }
        $berkasRef = $git.'/'.$ref;
        if (is_file($berkasRef)) {
            $hash = strtolower(trim((string) @file_get_contents($berkasRef)));
            $mtime = @filemtime($berkasRef);

            return $this->hashSah($hash)
                ? ['hash' => $hash, 'waktu' => $mtime ? Carbon::createFromTimestamp($mtime)->toIso8601String() : null]
                : null;
        }

        // Ref sudah dipadatkan (git gc): cari di packed-refs.
        foreach (@file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $baris) {
            if (str_ends_with($baris, ' '.$ref)) {
                $hash = strtolower(strtok($baris, ' '));

                return $this->hashSah($hash) ? ['hash' => $hash, 'waktu' => null] : null;
            }
        }

        return null;
    }

    public function detakTerakhir(): ?string
    {
        try {
            $nilai = Cache::get(self::KUNCI_DETAK);

            return is_string($nilai) && $nilai !== '' ? $nilai : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Dipanggil scheduler tiap menit; tidak boleh menggagalkan schedule:run. */
    public function catatDetak(): void
    {
        try {
            Cache::forever(self::KUNCI_DETAK, now()->toIso8601String());
        } catch (Throwable) {
            // Cache tak tersedia: endpoint akan melaporkan null.
        }
    }

    public function migrasiTertunda(): ?int
    {
        try {
            $migrator = app('migrator');
            $repo = $migrator->getRepository();
            $sudah = $repo->repositoryExists() ? $repo->getRan() : [];
            $berkas = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));

            return count(array_diff(array_keys($berkas), $sudah));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Baris log ERROR ke atas dalam 24 jam terakhir, dari laravel.log dan
     * laravel-YYYY-MM-DD.log hari ini/kemarin. Null bila ada berkas yang
     * tidak terbaca.
     */
    public function hitungError(?string $folder = null): ?int
    {
        $folder ??= storage_path('logs');
        $batas = now()->subDay();
        $berkas = [
            $folder.'/laravel.log',
            $folder.'/laravel-'.now()->format('Y-m-d').'.log',
            $folder.'/laravel-'.now()->subDay()->format('Y-m-d').'.log',
        ];

        $jumlah = 0;
        foreach ($berkas as $path) {
            if (! file_exists($path)) {
                continue;
            }
            $isi = $this->ekor($path);
            if ($isi === null) {
                return null;
            }
            foreach (explode("\n", $isi) as $baris) {
                if (! preg_match(self::POLA_ERROR, $baris, $m)) {
                    continue;
                }
                try {
                    if (Carbon::parse($m[1])->greaterThanOrEqualTo($batas)) {
                        $jumlah++;
                    }
                } catch (Throwable) {
                    // Cap waktu tak terbaca: lewati baris ini.
                }
            }
        }

        return $jumlah;
    }

    public function antrianGagal(): ?int
    {
        try {
            $koneksi = config('queue.failed.database') ?: config('database.default');
            $tabel = (string) config('queue.failed.table', 'failed_jobs');
            if (! Schema::connection($koneksi)->hasTable($tabel)) {
                return null;
            }

            return DB::connection($koneksi)->table($tabel)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** Isi ±2 MB terakhir berkas; baris pertama yang terpotong dibuang. */
    private function ekor(string $path): ?string
    {
        $f = @fopen($path, 'rb');
        if ($f === false) {
            return null;
        }
        try {
            $ukuran = (int) @filesize($path);
            $mulai = max(0, $ukuran - self::BATAS_LOG);
            if ($mulai > 0 && fseek($f, $mulai) !== 0) {
                return null;
            }
            $isi = stream_get_contents($f);
            if ($isi === false) {
                return null;
            }
            if ($mulai > 0 && ($pos = strpos($isi, "\n")) !== false) {
                $isi = substr($isi, $pos + 1);
            }

            return $isi;
        } finally {
            fclose($f);
        }
    }

    private function hashSah(string $hash): bool
    {
        return (bool) preg_match('/^[0-9a-f]{7,64}$/', $hash);
    }
}
