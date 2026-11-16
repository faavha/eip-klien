<?php

namespace Fmipa\EipKlien\Tests;

use Firebase\JWT\JWT;
use Fmipa\EipKlien\PengumpulStatus;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class StatusTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eipPalsu();
        $this->folder = sys_get_temp_dir().'/eip-klien-uji-'.Str::random(8);
        File::makeDirectory($this->folder, 0755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Token Peta Sistem yang sah; `$klaim` menimpa isinya (null = buang). */
    private function token(array $klaim = [], ?string $kunci = null, string $alg = 'RS256'): string
    {
        $isi = array_filter(array_merge([
            'iss' => self::ISSUER, 'aud' => 'uji', 'sub' => 'peta-sistem',
            'iat' => time(), 'exp' => time() + 120, 'jti' => Str::uuid()->toString(),
        ], $klaim), fn ($v) => $v !== null);

        return JWT::encode($isi, $kunci ?? $this->kunciPrivat, $alg, 'eip-1');
    }

    private function minta(?string $token)
    {
        return $this->withHeaders($token ? ['Authorization' => 'Bearer '.$token] : [])->getJson('/eip-klien/status');
    }

    public function test_route_terdaftar_tanpa_grup_web(): void
    {
        $route = app('router')->getRoutes()->getByName('eip-klien.status');
        $this->assertNotNull($route);
        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    public function test_tanpa_token_401(): void
    {
        $this->minta(null)->assertUnauthorized()->assertExactJson(['pesan' => 'Tidak diizinkan.']);
    }

    public function test_tanda_tangan_salah_401(): void
    {
        $lain = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($lain, $kunciLain);

        $this->minta($this->token([], $kunciLain))->assertUnauthorized();
    }

    public function test_hs256_dan_alg_none_ditolak(): void
    {
        // HS256 dengan "kunci" = isi JWK publik (serangan kebingungan algoritma).
        $this->minta($this->token([], json_encode($this->jwk), 'HS256'))->assertUnauthorized();

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $none = $b64(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'eip-1'])).'.'
            .$b64(json_encode(['iss' => self::ISSUER, 'aud' => 'uji', 'sub' => 'peta-sistem', 'iat' => time(), 'exp' => time() + 60, 'jti' => 'x'])).'.';
        $this->minta($none)->assertUnauthorized();
    }

    public function test_aud_salah_401(): void
    {
        $this->minta($this->token(['aud' => 'aplikasi-lain']))->assertUnauthorized();
    }

    public function test_iss_sub_jti_wajib(): void
    {
        $this->minta($this->token(['iss' => 'https://penipu.test']))->assertUnauthorized();
        $this->minta($this->token(['sub' => 'pegawai-1']))->assertUnauthorized();
        $this->minta($this->token(['jti' => null]))->assertUnauthorized();
    }

    public function test_exp_lewat_401(): void
    {
        $this->minta($this->token(['iat' => time() - 400, 'exp' => time() - 100]))->assertUnauthorized();
    }

    public function test_umur_token_lebih_dari_lima_menit_401(): void
    {
        $this->minta($this->token(['exp' => time() + 3600]))->assertUnauthorized();
    }

    public function test_token_tidak_bisa_dipakai_ulang(): void
    {
        $token = $this->token();

        $this->minta($token)->assertOk();
        $this->minta($token)->assertUnauthorized();
    }

    public function test_token_valid_200_dengan_semua_kunci(): void
    {
        $res = $this->minta($this->token())->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $res->assertJsonStructure([
            'kode', 'paket_versi', 'laravel', 'php', 'app_env', 'app_debug', 'commit', 'commit_waktu',
            'cron_detak_terakhir', 'migrasi_tertunda', 'error_24jam', 'antrian_gagal', 'waktu_server',
        ]);
        $this->assertSame('uji', $res->json('kode'));
        $this->assertSame(PHP_VERSION, $res->json('php'));
        $this->assertIsBool($res->json('app_debug'));
        $this->assertStringNotContainsString(base_path(), $res->getContent());
        // JWKS diambil dari EIP (discovery 404 → alamat bawaan).
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/oauth/jwks'));
    }

    public function test_aud_berupa_array_diterima(): void
    {
        $this->minta($this->token(['aud' => ['lain', 'uji']]))->assertOk();
    }

    public function test_membaca_revision(): void
    {
        file_put_contents($this->folder.'/REVISION', "ABCDEF1234567890abcdef1234567890abcdef12\n2026-11-16T10:00:00+07:00\n");
        $p = app(PengumpulStatus::class);

        $this->assertSame(
            ['hash' => 'abcdef1234567890abcdef1234567890abcdef12', 'waktu' => '2026-11-16T10:00:00+07:00'],
            $p->dariRevision($this->folder.'/REVISION'),
        );

        // Tanpa baris 2: waktu dari mtime berkas.
        file_put_contents($this->folder.'/REVISION', "abcdef1\n");
        touch($this->folder.'/REVISION', 1_700_000_000);
        clearstatcache();
        $hasil = $p->dariRevision($this->folder.'/REVISION');
        $this->assertSame('abcdef1', $hasil['hash']);
        $this->assertSame(1_700_000_000, Carbon::parse($hasil['waktu'])->getTimestamp());

        // Isi bukan hash → diabaikan.
        file_put_contents($this->folder.'/REVISION', "bukan hash\n");
        $this->assertNull($p->dariRevision($this->folder.'/REVISION'));
        $this->assertNull($p->dariRevision($this->folder.'/tidak-ada'));
    }

    public function test_membaca_git_tanpa_exec(): void
    {
        $git = $this->folder.'/.git';
        File::makeDirectory($git.'/refs/heads', 0755, true);
        file_put_contents($git.'/HEAD', "ref: refs/heads/main\n");
        $p = app(PengumpulStatus::class);

        // Ref padat (packed-refs).
        file_put_contents($git.'/packed-refs', "# pack-refs with: peeled fully-peeled sorted\n1111111111111111111111111111111111111111 refs/heads/lain\n2222222222222222222222222222222222222222 refs/heads/main\n");
        $this->assertSame(str_repeat('2', 40), $p->dariGit($git)['hash']);

        // Ref lepas lebih dulu.
        file_put_contents($git.'/refs/heads/main', str_repeat('3', 40)."\n");
        $this->assertSame(str_repeat('3', 40), $p->dariGit($git)['hash']);

        // HEAD terlepas.
        file_put_contents($git.'/HEAD', str_repeat('4', 40)."\n");
        $this->assertSame(str_repeat('4', 40), $p->dariGit($git)['hash']);

        $this->assertNull($p->dariGit($this->folder.'/tidak-ada'));
    }

    public function test_hitung_error_24_jam(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $log = implode("\n", [
            '[2026-11-14 12:00:00] production.ERROR: terlalu lama {"x":1}',
            '[2026-11-15 13:00:00] production.ERROR: dihitung',
            '#0 /var/www/app/Foo.php(12): jejak tumpukan',
            '[2026-11-16 08:00:00] production.INFO: bukan error',
            '[2026-11-16 09:00:00] production.CRITICAL: dihitung',
            '[2026-11-16T10:00:00.123456+00:00] local.EMERGENCY: dihitung',
            '[2026-11-16 11:00:00] production.WARNING: bukan error',
        ])."\n";
        file_put_contents($this->folder.'/laravel.log', $log);
        file_put_contents($this->folder.'/laravel-2026-11-15.log', "[2026-11-15 20:00:00] production.ALERT: dihitung\n");
        file_put_contents($this->folder.'/laravel-2026-11-10.log', "[2026-11-16 11:00:00] production.ERROR: berkas lama tidak dibaca\n");

        $this->assertSame(4, app(PengumpulStatus::class)->hitungError($this->folder));
        $this->assertSame(0, app(PengumpulStatus::class)->hitungError($this->folder.'/kosong'));
    }

    public function test_hitung_error_hanya_membaca_ekor_berkas(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $baris = "[2026-11-16 09:00:00] production.ERROR: x\n";
        // 3 MB baris error: hanya ±2 MB terakhir yang dibaca.
        file_put_contents($this->folder.'/laravel.log', str_repeat($baris, (int) ceil(3 * 1024 * 1024 / strlen($baris))));

        $jumlah = app(PengumpulStatus::class)->hitungError($this->folder);
        $this->assertSame((int) floor(PengumpulStatus::BATAS_LOG / strlen($baris)), $jumlah);
    }

    public function test_detak_cron_lewat_scheduler(): void
    {
        $this->assertNull(app(PengumpulStatus::class)->detakTerakhir());

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e instanceof CallbackEvent && $e->description === 'eip-klien:detak');
        $this->assertNotNull($event, 'detak terdaftar di scheduler');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertFalse($event->withoutOverlapping);

        Carbon::setTestNow('2026-11-16 12:34:00');
        $event->run($this->app);

        $this->assertSame(Carbon::now()->toIso8601String(), Cache::get('eip-klien:detak'));
        $this->assertSame(Carbon::now()->toIso8601String(), $this->minta($this->token())->json('cron_detak_terakhir'));
    }

    public function test_migrasi_dan_antrian(): void
    {
        $p = app(PengumpulStatus::class);
        // Migrasi paket (impersonasi_log) belum dijalankan di DB uji.
        $this->assertGreaterThanOrEqual(1, $p->migrasiTertunda());
        $this->assertNull($p->antrianGagal());
    }
}
