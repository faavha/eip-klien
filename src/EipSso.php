<?php

namespace Fmipa\EipKlien;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Klien SSO ke EIP Core (OpenID Connect, ADR 0018 di eip-contracts).
 *
 * Alurnya (Authorization Code + PKCE):
 *  1. `alamatLogin()` — tiga nilai acak: `state` (anti CSRF), `nonce` (anti
 *     token dipakai ulang), dan `verifier` (PKCE). EIP hanya menerima hash
 *     verifier (code_challenge).
 *  2. Pengguna login di EIP (lewat Google), lalu EIP mengembalikannya ke
 *     `/auth/eip/callback` dengan sebuah `code`.
 *  3. `tukarKode()` — server menukar `code` + `verifier` + secret ke EIP dan
 *     mendapat `id_token` (JWT bertanda tangan).
 *  4. `bacaIdToken()` — tanda tangan diperiksa dengan kunci publik EIP
 *     (JWKS), lalu isi token dipastikan untuk aplikasi ini, dari EIP, belum
 *     kedaluwarsa, dan nonce cocok.
 */
class EipSso
{
    public function __construct(private readonly KunciEip $kunci) {}

    /** @return array{url: string, state: string, nonce: string, verifier: string} */
    public function alamatLogin(): array
    {
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $url = $this->kunci->discovery()['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => (string) config('eip-klien.sso.scope', 'openid profile email peran'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        return compact('url', 'state', 'nonce', 'verifier');
    }

    /** @return array<string, mixed> respons token dari EIP (berisi id_token) */
    public function tukarKode(string $code, string $verifier): array
    {
        $res = $this->http()->asForm()->post($this->kunci->discovery()['token_endpoint'], [
            'grant_type' => 'authorization_code',
            'client_id' => $this->clientId(),
            'client_secret' => config('eip-klien.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        if (! $res->successful() || ! is_string($res->json('id_token'))) {
            throw new RuntimeException('EIP menolak penukaran kode login (HTTP '.$res->status().').');
        }

        return $res->json();
    }

    /**
     * Periksa tanda tangan & isi ID token, kembalikan klaimnya.
     *
     * @return array<string, mixed>
     */
    public function bacaIdToken(string $idToken, string $nonce): array
    {
        // Tanda tangan (JWKS, hanya RS256) + exp/nbf/iat, toleransi 60 detik.
        $klaim = $this->kunci->dekode($idToken, 60);

        $aud = (array) ($klaim['aud'] ?? []);
        $periksa = [
            'penerbit (iss)' => ($klaim['iss'] ?? null) === $this->issuer(),
            'penerima (aud)' => in_array($this->clientId(), $aud, true),
            'nonce' => hash_equals($nonce, (string) ($klaim['nonce'] ?? '')),
            'subjek (sub)' => filled($klaim['sub'] ?? null),
            'kedaluwarsa (exp)' => is_numeric($klaim['exp'] ?? null),
        ];
        foreach ($periksa as $nama => $lolos) {
            if (! $lolos) {
                throw new RuntimeException("ID token dari EIP tidak valid: {$nama}.");
            }
        }

        return $klaim;
    }

    /** Alamat logout terpusat di EIP (keluar dari semua aplikasi FMIPA). */
    public function alamatLogout(?string $kembaliKe = null): string
    {
        $kembaliKe ??= route((string) config('eip-klien.sso.setelah_logout', 'login'));

        return ($this->kunci->discovery()['end_session_endpoint'] ?? $this->issuer().'/oauth/logout')
            .'?'.http_build_query(['post_logout_redirect_uri' => $kembaliKe]);
    }

    public function issuer(): string
    {
        return $this->kunci->issuer();
    }

    private function clientId(): string
    {
        return (string) config('eip-klien.client_id');
    }

    private function redirectUri(): string
    {
        return route('auth.eip.callback');
    }

    private function http(): PendingRequest
    {
        return $this->kunci->http();
    }
}
