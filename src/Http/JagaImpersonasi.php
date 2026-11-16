<?php

namespace Fmipa\EipKlien\Http;

use Closure;
use Fmipa\EipKlien\Impersonasi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dipasang paket di akhir grup `web` saat impersonasi aktif (ADR 0019 C):
 *  - lewat batas menit → kembali ke akun asli;
 *  - route logout → hentikan menyamar dulu, lalu logout aplikasi berjalan
 *    untuk akun asli (tidak ada sesi setengah);
 *  - route terblokir → ditolak;
 *  - konteks log diberi `impersonator_id`.
 */
class JagaImpersonasi
{
    public function __construct(private readonly Impersonasi $impersonasi) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->impersonasi->aktif()) {
            return $next($request);
        }

        $config = config('eip-klien.impersonasi');
        $flash = $config['flash'] ?? [];

        if ($request->routeIs((string) ($config['route_keluar'] ?? 'logout'))) {
            $this->impersonasi->hentikan('keluar');

            return $next($request);
        }

        if ($this->impersonasi->kedaluwarsa()) {
            $this->impersonasi->hentikan('kedaluwarsa');

            return redirect()->route($config['beranda'] ?? 'dashboard')
                ->with($flash['sukses'] ?? 'success', 'Waktu masuk sebagai sudah habis; Anda kembali ke akun sendiri.');
        }

        Log::withContext(['impersonator_id' => $this->impersonasi->pelakuId()]);

        $diblokir = (array) ($config['diblokir'] ?? []);
        if ($diblokir !== [] && $request->routeIs(...$diblokir)) {
            $pesan = 'Halaman ini tidak tersedia selama masuk sebagai pengguna lain.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $pesan], 403);
            }

            return redirect()->to(url()->previous(route($config['beranda'] ?? 'dashboard')))
                ->with($flash['gagal'] ?? 'error', $pesan);
        }

        return $next($request);
    }
}
