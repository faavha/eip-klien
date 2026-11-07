<?php

namespace Fmipa\EipKlien\Tests;

use Firebase\JWT\JWT;
use Fmipa\EipKlien\EipKlienServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const ISSUER = 'https://eip.test';

    protected const CLIENT = 'client-uji';

    protected string $kunciPrivat = '';

    /** @var array<string, mixed> */
    protected array $jwk = [];

    /** @var array<string, mixed> */
    protected array $klaim = [];

    protected ?string $kunciToken = null;

    protected function getPackageProviders($app): array
    {
        return [EipKlienServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('eip-klien.base_url', self::ISSUER);
        $app['config']->set('eip-klien.api_token', 'token-uji');
        $app['config']->set('eip-klien.client_id', self::CLIENT);
        $app['config']->set('eip-klien.client_secret', 'rahasia');
        $app['config']->set('eip-klien.kode_aplikasi', 'uji');
        $app['config']->set('eip-klien.resolve_ip', null);
    }

    protected function defineRoutes($router): void
    {
        Route::middleware('web')->group(function () {
            Route::get('/', fn () => 'halaman login')->name('login');
            Route::get('/dashboard', fn () => 'dasbor')->name('dashboard');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $kunci = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($kunci, $this->kunciPrivat);
        $rsa = openssl_pkey_get_details($kunci)['rsa'];
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $this->jwk = ['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'eip-1', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])];
    }

    /** Palsukan endpoint OIDC EIP; `$klaim` menimpa isi ID token. */
    protected function eipPalsu(array $klaim = [], ?string $kunci = null): void
    {
        $this->klaim = $klaim;
        $this->kunciToken = $kunci;
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_ends_with($url, '/.well-known/openid-configuration')) {
                return Http::response([], 404);
            }
            if (str_ends_with($url, '/oauth/jwks')) {
                return Http::response(['keys' => [$this->jwk]]);
            }
            if (str_ends_with($url, '/oauth/token')) {
                $isi = array_merge([
                    'iss' => self::ISSUER, 'aud' => [self::CLIENT], 'sub' => '01UIDPEGAWAI',
                    'iat' => time(), 'exp' => time() + 3600, 'nonce' => session('eip_sso_uji_nonce'),
                    'name' => 'Dr. Uji', 'email' => 'Uji@Staff.uns.ac.id',
                    'peran' => ['admin'],
                    'peran_unit' => [['peran' => 'admin', 'id' => 7, 'kode' => 'S1KIM', 'nama' => 'S-1 Kimia']],
                ], $this->klaim);
                $isi = array_filter($isi, fn ($v) => $v !== null);

                return Http::response(['access_token' => 'x', 'token_type' => 'Bearer', 'expires_in' => 600,
                    'id_token' => JWT::encode($isi, $this->kunciToken ?? $this->kunciPrivat, 'RS256', 'eip-1')]);
            }

            return Http::response([], 404);
        });
    }
}
