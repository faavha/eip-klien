<?php

namespace Fmipa\EipKlien\Http;

use Fmipa\EipKlien\KunciEip;
use Fmipa\EipKlien\PengumpulStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * `GET /eip-klien/status` — kondisi aplikasi untuk Peta Sistem EIP.
 *
 * Tanpa secret baru: EIP mengirim `Authorization: Bearer <JWT>` yang
 * ditandatangani kunci OIDC-nya (kunci yang sama dengan ID token), lalu
 * diperiksa dengan JWKS EIP. Klaim wajib: iss = issuer EIP, aud = kode
 * aplikasi ini, sub = `peta-sistem`, umur ≤ 5 menit, jti (sekali pakai).
 */
class StatusController
{
    public const SUBJEK = 'peta-sistem';

    /** Umur maksimum token (exp − iat), detik. */
    public const UMUR_MAKS = 300;

    /** Toleransi beda jam antar server, detik. */
    public const LEEWAY = 30;

    public function __invoke(Request $request, KunciEip $kunci, PengumpulStatus $pengumpul): JsonResponse
    {
        if (! $this->tokenSah((string) $request->bearerToken(), $kunci)) {
            return response()->json(['pesan' => 'Tidak diizinkan.'], 401)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json($pengumpul->kumpulkan())->header('Cache-Control', 'no-store');
    }

    private function tokenSah(string $token, KunciEip $kunci): bool
    {
        $kode = (string) config('eip-klien.kode_aplikasi');
        if ($token === '' || $kode === '') {
            return false;
        }

        try {
            $klaim = $kunci->dekode($token, self::LEEWAY);
        } catch (Throwable $e) {
            Log::info('eip-klien: token status ditolak', ['alasan' => Str::limit($e->getMessage(), 120)]);

            return false;
        }

        $iat = $klaim['iat'] ?? null;
        $exp = $klaim['exp'] ?? null;
        $jti = $klaim['jti'] ?? null;
        $sah = ($klaim['iss'] ?? null) === $kunci->issuer()
            && in_array($kode, (array) ($klaim['aud'] ?? []), true)
            && ($klaim['sub'] ?? null) === self::SUBJEK
            && is_numeric($iat) && is_numeric($exp)
            && $exp > $iat && $exp - $iat <= self::UMUR_MAKS
            && is_string($jti) && $jti !== '' && strlen($jti) <= 100;
        if (! $sah) {
            return false;
        }

        // Sekali pakai: token yang sama tidak bisa diulang selama masa berlakunya.
        try {
            return Cache::add('eip-klien:jti:'.hash('sha256', $jti), true, (int) ceil($exp - time()) + self::LEEWAY);
        } catch (Throwable) {
            return true; // cache rusak: tanda tangan & umur pendek tetap melindungi
        }
    }
}
