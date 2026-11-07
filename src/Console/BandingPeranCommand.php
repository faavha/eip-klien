<?php

namespace Fmipa\EipKlien\Console;

use Fmipa\EipKlien\Contracts\PeranLokal;
use Fmipa\EipKlien\EipClient;
use Fmipa\EipKlien\PeranEip;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pemeriksaan SEBELUM peran aplikasi dialihkan ke EIP (baca saja): bandingkan
 * peran lokal tiap akun dengan peran menurut EIP. Tidak mengubah data.
 */
class BandingPeranCommand extends Command
{
    protected $signature = 'eip:banding-peran';

    protected $description = 'Bandingkan peran lokal aplikasi dengan peran di EIP (tanpa mengubah data)';

    public function handle(EipClient $eip): int
    {
        if (! app()->bound(PeranLokal::class)) {
            $this->error('Aplikasi belum mendaftarkan '.PeranLokal::class.' (lihat README eip-klien).');

            return self::FAILURE;
        }

        /** @var PeranLokal $lokal */
        $lokal = app(PeranLokal::class);
        $kodePeran = $lokal->kodePeran();
        $baris = [];

        foreach ($lokal->akun() as $email => $peranLokal) {
            $email = strtolower(trim((string) $email));
            $peranLokal = array_values(array_intersect($kodePeran, $peranLokal));
            try {
                $peran = $eip->pegawaiByEmail($email) ? PeranEip::dariAkses($eip->akses($email)) : null;
            } catch (Throwable $e) {
                $baris[] = [$email, implode(',', $peranLokal), '?', 'GAGAL: '.$e->getMessage()];

                continue;
            }

            if ($peran === null) {
                if ($peranLokal !== []) {
                    $baris[] = [$email, implode(',', $peranLokal), '—', 'bukan pegawai EIP: tak bisa login lewat EIP'];
                }

                continue;
            }

            $peranEip = array_values(array_intersect($kodePeran, $peran->kode()));
            $hilang = array_diff($peranLokal, $peranEip);
            $baru = array_diff($peranEip, $peranLokal);
            if ($hilang || $baru) {
                $baris[] = [$email, implode(',', $peranLokal) ?: '—', implode(',', $peranEip) ?: '—',
                    trim(($hilang ? 'HILANG: '.implode(',', $hilang).' ' : '').($baru ? 'baru: '.implode(',', $baru) : ''))];
            }
        }

        if ($baris === []) {
            $this->info('Semua peran lokal sudah sama dengan EIP. Aman dialihkan.');

            return self::SUCCESS;
        }

        $this->table(['Email', 'Lokal', 'Menurut EIP', 'Dampak'], $baris);
        $this->line('Baris "HILANG": beri perannya di EIP → Akses → aplikasi ini sebelum dialihkan.');

        return self::SUCCESS;
    }
}
