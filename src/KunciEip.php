<?php

namespace Fmipa\EipKlien;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Kunci publik EIP (discovery OIDC + JWKS) dan pemeriksa tanda tangan JWT
 * terbitan EIP. Dipakai bersama oleh SSO (ID token) dan endpoint status
 * (token Peta Sistem), sehingga cache JWKS dan resolve IP-nya sama.
 *
 * Hanya RS256 yang diterima: kunci dari JWKS diikat ke RS256, jadi token
 * ber-`alg` none atau HS* selalu ditolak.
 */
class KunciEip
{
    public const ALGORITMA = 'RS256';

    /**
     * Periksa tanda tangan + waktu (exp, nbf, iat) sebuah JWT dari EIP lalu
     * kembalikan klaimnya. Klaim lain (iss, aud, …) diperiksa pemanggil.
     *
     * @param  int  $leeway  toleransi beda jam antar server (detik)
     * @return array<string, mixed>
     */
    public function dekode(string $jwt, int $leeway = 60): array
    {
        $leewayLama = JWT::$leeway;
        JWT::$leeway = $leeway;

        try {
            try {
                $klaim = JWT::decode($jwt, JWK::parseKeySet($this->jwks(), self::ALGORITMA));
            } catch (\Throwable $e) {
                // Kunci EIP mungkin baru dirotasi: ambil ulang JWKS sekali lagi,
                // paling sering sekali per menit (token sampah tidak membanjiri EIP).
                if (! Cache::add('eip.sso.jwks-diambil-ulang', true, 60)) {
                    throw $e;
                }
                Cache::forget('eip.sso.jwks');
                $klaim = JWT::decode($jwt, JWK::parseKeySet($this->jwks(), self::ALGORITMA));
            }
        } finally {
            JWT::$leeway = $leewayLama;
        }

        // Ubah objek bertingkat (stdClass) menjadi array biasa.
        return json_decode(json_encode($klaim), true);
    }

    /** @return array<string, mixed> */
    public function discovery(): array
    {
        return Cache::remember('eip.sso.discovery', now()->addHour(), function () {
            $res = $this->http()->get($this->issuer().'/.well-known/openid-configuration');

            // Bila web server EIP belum meneruskan /.well-known/ (404), pakai
            // alamat bawaan EIP. Keamanan tidak berkurang: token tetap
            // diverifikasi dengan kunci publik (JWKS) EIP.
            if ($res->status() === 404) {
                return [
                    'authorization_endpoint' => $this->issuer().'/oauth/authorize',
                    'token_endpoint' => $this->issuer().'/oauth/token',
                    'jwks_uri' => $this->issuer().'/oauth/jwks',
                    'end_session_endpoint' => $this->issuer().'/oauth/logout',
                ];
            }
            if (! $res->successful()) {
                throw new RuntimeException('Tidak bisa menghubungi EIP (discovery HTTP '.$res->status().').');
            }

            return $res->json();
        });
    }

    /** @return array<string, mixed> */
    public function jwks(): array
    {
        return Cache::remember('eip.sso.jwks', now()->addHour(), function () {
            return $this->http()->get($this->discovery()['jwks_uri'])->throw()->json();
        });
    }

    public function issuer(): string
    {
        return rtrim((string) (config('eip-klien.issuer') ?: config('eip-klien.base_url')), '/');
    }

    public function http(): PendingRequest
    {
        return Http::ke($this->issuer());
    }
}
