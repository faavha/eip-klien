<?php

namespace Fmipa\EipKlien\Http;

use Fmipa\EipKlien\Contracts\PenanganLogin;
use Fmipa\EipKlien\EipSso;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Login lewat SSO EIP (OIDC + PKCE, ADR 0018). Peran TIDAK diatur aplikasi:
 * EIP yang menentukan (ADR 0017) dan mengirimnya di ID token.
 */
class EipLoginController extends Controller
{
    public function __construct(
        private readonly EipSso $sso,
        private readonly PenanganLogin $penangan,
    ) {}

    /** Langkah 1: kirim pengguna ke halaman login EIP. */
    public function redirect(Request $request): Response
    {
        try {
            $login = $this->sso->alamatLogin();
        } catch (Throwable $e) {
            report($e);

            return $this->penangan->gagal('EIP sedang tidak bisa dihubungi. Coba lagi sebentar lagi.');
        }

        // Nilai acak dicocokkan saat EIP mengembalikan pengguna.
        $request->session()->put('eip_sso', ['state' => $login['state'], 'nonce' => $login['nonce'], 'verifier' => $login['verifier']]);

        return redirect()->away($login['url']);
    }

    /** Langkah 2: EIP mengembalikan pengguna ke sini membawa `code`. */
    public function callback(Request $request): Response
    {
        $sesi = $request->session()->pull('eip_sso');

        if ($request->filled('error')) {
            return $this->penangan->gagal('Login dibatalkan di EIP.');
        }
        if (! is_array($sesi) || ! hash_equals((string) ($sesi['state'] ?? ''), (string) $request->query('state'))) {
            return $this->penangan->gagal('Sesi login kedaluwarsa. Silakan klik "Masuk dengan akun FMIPA" lagi.');
        }

        try {
            $token = $this->sso->tukarKode((string) $request->query('code'), $sesi['verifier']);
            $klaim = $this->sso->bacaIdToken($token['id_token'], $sesi['nonce']);
            $klaim['email'] = strtolower(trim((string) ($klaim['email'] ?? '')));
        } catch (Throwable $e) {
            Log::warning('Login SSO EIP gagal', ['pesan' => $e->getMessage()]);

            return $this->penangan->gagal('Login lewat EIP gagal. Coba lagi, atau hubungi admin bila berulang.');
        }

        $hasil = $this->penangan->masuk($klaim, $request);
        if (! $hasil instanceof Authenticatable) {
            return $hasil;
        }

        Auth::login($hasil, (bool) config('eip-klien.sso.ingat', false));
        $request->session()->regenerate(); // cegah session fixation
        $request->session()->put('login_via_eip', true);

        return redirect()->intended(route((string) config('eip-klien.sso.setelah_login', 'dashboard')));
    }
}
