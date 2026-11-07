<?php

namespace Fmipa\EipKlien\Tests;

use Fmipa\EipKlien\Contracts\PenanganLogin;
use Fmipa\EipKlien\EipSso;
use Fmipa\EipKlien\PeranEip;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

class SsoTest extends TestCase
{
    /** @var array<string, mixed>|null klaim yang diterima penangan */
    public static ?array $diterima = null;

    protected function setUp(): void
    {
        parent::setUp();
        self::$diterima = null;

        $this->app->bind(PenanganLogin::class, fn () => new class implements PenanganLogin
        {
            public function masuk(array $klaim, Request $request): Authenticatable|Response
            {
                SsoTest::$diterima = $klaim;
                if (PeranEip::dariKlaim($klaim)->kosong()) {
                    return $this->gagal('Belum punya peran.');
                }

                return new GenericUser(['id' => 42, 'email' => $klaim['email']]);
            }

            public function gagal(string $pesan): Response
            {
                return redirect()->route('login')->with('error', $pesan);
            }
        });
    }

    private function loginLewatEip(): TestResponse
    {
        $res = $this->get(route('auth.eip'));
        $lokasi = (string) $res->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/oauth/authorize?', $lokasi);
        parse_str((string) parse_url($lokasi, PHP_URL_QUERY), $q);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(self::CLIENT, $q['client_id']);
        $this->assertSame('openid profile email peran', $q['scope']);
        session(['eip_sso_uji_nonce' => $q['nonce']]);

        return $this->get(route('auth.eip.callback', ['code' => 'kode', 'state' => $q['state']]));
    }

    public function test_login_berhasil_masuk_dan_klaim_diteruskan(): void
    {
        $this->eipPalsu();

        $this->loginLewatEip()->assertRedirect(route('dashboard'));

        $this->assertSame(42, Auth::id());
        $this->assertTrue(session('login_via_eip'));
        $this->assertSame('uji@staff.uns.ac.id', self::$diterima['email']); // dinormalkan
        $peran = PeranEip::dariKlaim(self::$diterima);
        $this->assertSame([7], $peran->unitUntuk('admin'));
        $this->assertSame('S1KIM', $peran->detailUnitUntuk('admin')[0]['kode']);
    }

    public function test_penangan_boleh_menolak_dengan_response_sendiri(): void
    {
        $this->eipPalsu(['peran' => [], 'peran_unit' => []]);

        $this->loginLewatEip()->assertRedirect(route('login'))->assertSessionHas('error', 'Belum punya peran.');
        $this->assertGuest();
    }

    public function test_tanda_tangan_palsu_ditolak(): void
    {
        $lain = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($lain, $kunciLain);
        $this->eipPalsu([], $kunciLain);

        $this->loginLewatEip()->assertRedirect(route('login'))->assertSessionHas('error');
        $this->assertGuest();
        $this->assertNull(self::$diterima);
    }

    public function test_token_untuk_aplikasi_lain_ditolak(): void
    {
        $this->eipPalsu(['aud' => ['aplikasi-lain']]);

        $this->loginLewatEip()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_penerbit_lain_ditolak(): void
    {
        $this->eipPalsu(['iss' => 'https://penipu.test']);

        $this->loginLewatEip()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_token_kedaluwarsa_ditolak(): void
    {
        $this->eipPalsu(['iat' => time() - 7200, 'exp' => time() - 3600]);

        $this->loginLewatEip()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_token_tanpa_exp_ditolak(): void
    {
        $this->eipPalsu(['exp' => null]);

        $this->loginLewatEip()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_nonce_salah_ditolak(): void
    {
        $this->eipPalsu(['nonce' => 'nonce-lain']);

        $this->loginLewatEip()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_jwks_lama_di_cache_diambil_ulang_saat_kunci_dirotasi(): void
    {
        $this->eipPalsu();
        // JWKS usang (kunci lain) tersimpan di cache sebelum login.
        \Illuminate\Support\Facades\Cache::put('eip.sso.jwks', ['keys' => [array_merge($this->jwk, ['kid' => 'lama', 'n' => 'AQAB'])]], 3600);

        $this->loginLewatEip()->assertRedirect(route('dashboard'));
        $this->assertSame(42, Auth::id());
    }

    public function test_state_salah_ditolak_tanpa_menghubungi_token(): void
    {
        $this->eipPalsu();
        $this->get(route('auth.eip'));

        $this->get(route('auth.eip.callback', ['code' => 'x', 'state' => 'salah']))
            ->assertRedirect(route('login'))->assertSessionHas('error');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/oauth/token'));
    }

    public function test_eip_mati_saat_mulai_login(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $this->get(route('auth.eip'))->assertRedirect(route('login'))
            ->assertSessionHas('error', 'EIP sedang tidak bisa dihubungi. Coba lagi sebentar lagi.');
    }

    public function test_alamat_logout_terpusat(): void
    {
        $this->eipPalsu();

        $url = app(EipSso::class)->alamatLogout();
        $this->assertStringStartsWith(self::ISSUER.'/oauth/logout?post_logout_redirect_uri=', $url);
        $this->assertStringContainsString(urlencode(route('login')), $url);
    }

    public function test_issuer_boleh_beda_dengan_base_url(): void
    {
        config(['eip-klien.issuer' => 'https://idp.test/']);
        $this->assertSame('https://idp.test', app(EipSso::class)->issuer());
    }
}
