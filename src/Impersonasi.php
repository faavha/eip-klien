<?php

namespace Fmipa\EipKlien;

use Fmipa\EipKlien\Contracts\AturanImpersonasi;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Masuk sebagai" (ADR 0019). Selama menyamar `Auth::user()` = akun target;
 * pelaku asli disimpan di sesi supaya bisa dikembalikan dan dicatat.
 *
 * Isi sesi hanya nilai skalar (aman untuk cache/sesi tanpa objek).
 */
class Impersonasi
{
    public const SESI = 'eip_impersonasi';

    public function aktif(): bool
    {
        return is_array(session(self::SESI));
    }

    public function pelakuId(): int|string|null
    {
        return $this->data('pelaku_id');
    }

    public function pelakuNama(): ?string
    {
        return $this->data('pelaku_nama');
    }

    public function targetNama(): ?string
    {
        return $this->data('target_nama');
    }

    public function sisaDetik(): int
    {
        return max(0, (int) $this->data('sampai', 0) - now()->getTimestamp());
    }

    public function sisaMenit(): int
    {
        return (int) ceil($this->sisaDetik() / 60);
    }

    public function kedaluwarsa(): bool
    {
        return $this->aktif() && $this->sisaDetik() === 0;
    }

    /**
     * Tombol "Masuk sebagai" boleh ditampilkan untuk target ini? Tanpa
     * memanggil EIP (dipanggil per baris tabel).
     */
    public function bolehTampil(?Authenticatable $pelaku, Authenticatable $target): bool
    {
        if (! config('eip-klien.impersonasi.aktif') || ! $pelaku || $this->aktif()) {
            return false;
        }
        $aturan = $this->aturan();

        return $aturan->bolehMenyamar($pelaku)
            && ! $this->sama($pelaku, $target)
            && ! $aturan->dilindungi($target)
            && $aturan->aktif($target);
    }

    /**
     * Semua aturan ADR 0019 bagian B. Kembalikan pesan penolakan, atau null
     * bila boleh. Cek ke EIP paling akhir (satu-satunya panggilan jaringan).
     */
    public function periksa(Authenticatable $pelaku, Authenticatable $target): ?string
    {
        $aturan = $this->aturan();

        return match (true) {
            $this->aktif() => 'Anda sedang menyamar. Kembali ke akun Anda dulu.',
            ! $aturan->bolehMenyamar($pelaku) => 'Hanya admin yang boleh masuk sebagai pengguna lain.',
            $this->sama($pelaku, $target) => 'Tidak bisa masuk sebagai diri sendiri.',
            $aturan->dilindungi($target) => 'Akun ini dilindungi (admin atau akun cadangan).',
            ! $aturan->aktif($target) => 'Akun target nonaktif atau tidak punya peran.',
            default => $this->periksaEip($pelaku),
        };
    }

    /** Peran pelaku dibaca langsung dari EIP (tanpa cache). Gagal = tolak. */
    private function periksaEip(Authenticatable $pelaku): ?string
    {
        $email = $this->aturan()->email($pelaku);
        if (blank($email)) {
            return 'Akun Anda tidak terhubung ke EIP; masuk sebagai tidak tersedia.';
        }

        try {
            $akses = app(EipClient::class)->akses((string) $email);
        } catch (Throwable $e) {
            report($e);

            return 'EIP tidak bisa dihubungi untuk memastikan peran Anda. Coba lagi nanti.';
        }

        $peran = PeranEip::dariAkses($akses);
        $boleh = (bool) ($akses['boleh_masuk'] ?? false)
            && array_intersect($peran->kode(), (array) config('eip-klien.impersonasi.peran_pelaku', [])) !== [];

        return $boleh ? null : 'Peran admin Anda tidak ditemukan di EIP.';
    }

    /** Mulai menyamar. Panggil `periksa()` dulu. */
    public function mulai(Authenticatable $pelaku, Authenticatable $target, string $alasan, Request $request): ImpersonasiLog
    {
        $aturan = $this->aturan();

        $log = ImpersonasiLog::create([
            'pelaku_id' => (string) $pelaku->getAuthIdentifier(),
            'pelaku_nama' => Str::limit($aturan->nama($pelaku), 250),
            'target_id' => (string) $target->getAuthIdentifier(),
            'target_nama' => Str::limit($aturan->nama($target), 250),
            'alasan' => Str::limit(trim($alasan), 250),
            'mulai_at' => now(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        // Auth::login mengganti ID sesi (session()->migrate) — ADR 0019 C2.
        $this->masukSebagai($target);
        session([self::SESI => [
            'pelaku_id' => $pelaku->getAuthIdentifier(),
            'pelaku_nama' => $log->pelaku_nama,
            'target_nama' => $log->target_nama,
            'sampai' => now()->addMinutes((int) config('eip-klien.impersonasi.menit', 25))->getTimestamp(),
            'log_id' => $log->id,
        ]]);

        Log::info('eip-klien: mulai masuk sebagai', ['pelaku' => $log->pelaku_id, 'target' => $log->target_id, 'log' => $log->id]);

        return $log;
    }

    /**
     * Kembali ke akun asli. `$cara`: kembali | kedaluwarsa | keluar.
     * Kembalikan akun asli, atau null bila tidak sedang menyamar / akun asli
     * hilang (sesi lalu dikosongkan).
     */
    public function hentikan(string $cara): ?Authenticatable
    {
        $data = session()->pull(self::SESI);
        if (! is_array($data)) {
            return null;
        }

        $log = ImpersonasiLog::find($data['log_id'] ?? null);
        if ($log && ! $log->selesai()) {
            $log->update(['selesai_at' => now(), 'cara_selesai' => in_array($cara, ImpersonasiLog::CARA, true) ? $cara : 'kembali']);
        }

        $asli = $this->guard()->getProvider()->retrieveById($data['pelaku_id'] ?? null);
        if (! $asli) {
            $this->guard()->logout();
            session()->invalidate();
            session()->regenerateToken();

            return null;
        }

        $this->masukSebagai($asli);
        Log::info('eip-klien: selesai masuk sebagai', ['kembali_ke' => (string) $asli->getAuthIdentifier(), 'cara' => $cara]);

        return $asli;
    }

    private function masukSebagai(Authenticatable $pengguna): void
    {
        $guard = $this->guard();
        $guard->login($pengguna);
        // Middleware AuthenticateSession menyimpan hash password akun lama;
        // dibuang supaya disimpan ulang untuk akun baru, bukan memutus sesi.
        session()->forget('password_hash_'.Auth::getDefaultDriver());
        // Form yang terbuka sebelum identitas berganti tidak berlaku lagi.
        session()->regenerateToken();
    }

    private function guard(): StatefulGuard
    {
        /** @var StatefulGuard */
        return Auth::guard();
    }

    private function aturan(): AturanImpersonasi
    {
        return app(AturanImpersonasi::class);
    }

    private function sama(Authenticatable $a, Authenticatable $b): bool
    {
        return (string) $a->getAuthIdentifier() === (string) $b->getAuthIdentifier();
    }

    private function data(string $kunci, mixed $bawaan = null): mixed
    {
        $data = session(self::SESI);

        return is_array($data) ? ($data[$kunci] ?? $bawaan) : $bawaan;
    }
}
