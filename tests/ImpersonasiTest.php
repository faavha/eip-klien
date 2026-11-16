<?php

namespace Fmipa\EipKlien\Tests;

use Fmipa\EipKlien\Contracts\AturanImpersonasi;
use Fmipa\EipKlien\Impersonasi;
use Fmipa\EipKlien\ImpersonasiLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

class UjiPengguna extends User
{
    protected $table = 'pengguna_uji';

    protected $guarded = [];

    public $timestamps = false;
}

class ImpersonasiTest extends TestCase
{
    private UjiPengguna $admin;

    private UjiPengguna $admin2;

    private UjiPengguna $biasa;

    private UjiPengguna $nonaktif;

    private UjiPengguna $cadangan;

    /** Peran yang dijawab EIP palsu untuk `/akses`; null = EIP mati. */
    private ?array $peranEip = ['admin'];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', UjiPengguna::class);
        $app['config']->set('eip-klien.impersonasi.aktif', true);
        $app['config']->set('eip-klien.impersonasi.diblokir', ['eip.impersonasi.mulai', 'pengguna.*']);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);
        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/pengguna', fn () => 'daftar pengguna')->name('pengguna.index');
            Route::get('/laporan', fn () => 'laporan '.Auth::id())->name('laporan');
            Route::post('/keluar', function () {
                Auth::logout();
                session()->invalidate();

                return redirect('/');
            })->name('logout');
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pengguna_uji', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email');
            $t->string('password')->nullable();
            $t->string('peran')->nullable();
            $t->boolean('aktif')->default(true);
            $t->boolean('cadangan')->default(false);
        });
        $this->artisan('migrate')->run();

        $this->admin = UjiPengguna::create(['name' => 'Admin Satu', 'email' => 'admin@uns.ac.id', 'peran' => 'admin']);
        $this->admin2 = UjiPengguna::create(['name' => 'Admin Dua', 'email' => 'admin2@uns.ac.id', 'peran' => 'admin']);
        $this->biasa = UjiPengguna::create(['name' => 'Laboran Biasa', 'email' => 'laboran@uns.ac.id', 'peran' => 'laboran']);
        $this->nonaktif = UjiPengguna::create(['name' => 'Nonaktif', 'email' => 'lama@uns.ac.id', 'peran' => 'laboran', 'aktif' => false]);
        $this->cadangan = UjiPengguna::create(['name' => 'Cadangan', 'email' => 'cadangan@lokal', 'peran' => 'laboran', 'cadangan' => true]);
        // Muat ulang supaya nilai bawaan kolom (aktif, cadangan) ikut terbaca.
        foreach (['admin', 'admin2', 'biasa', 'nonaktif', 'cadangan'] as $p) {
            $this->{$p}->refresh();
        }

        $this->app->bind(AturanImpersonasi::class, fn () => new class implements AturanImpersonasi
        {
            public function bolehMenyamar(Authenticatable $pelaku): bool
            {
                return $pelaku->peran === 'admin';
            }

            public function dilindungi(Authenticatable $target): bool
            {
                return $target->peran === 'admin' || $target->cadangan;
            }

            public function aktif(Authenticatable $target): bool
            {
                return $target->aktif && $target->peran !== null;
            }

            public function email(Authenticatable $pengguna): ?string
            {
                return $pengguna->cadangan ? null : $pengguna->email;
            }

            public function nama(Authenticatable $pengguna): string
            {
                return $pengguna->name;
            }
        });

        Http::fake(function ($request) {
            if (! str_contains($request->url(), '/api/v1/akses')) {
                return Http::response([], 404);
            }
            if ($this->peranEip === null) {
                return Http::response(['message' => 'down'], 503);
            }

            return Http::response(['data' => [
                'boleh_masuk' => $this->peranEip !== [],
                'peran' => array_map(fn ($k) => ['kode' => $k, 'unit_kerja' => []], $this->peranEip),
            ]]);
        });
    }

    private function mulai(UjiPengguna $target, string $alasan = 'Testing')
    {
        return $this->post(route('eip.impersonasi.mulai', ['pengguna' => $target->id]), ['alasan' => $alasan]);
    }

    public function test_admin_masuk_sebagai_pengguna_biasa_dan_tercatat(): void
    {
        $this->actingAs($this->admin);
        session()->regenerateToken();
        $tokenLama = session()->token();

        $this->mulai($this->biasa, 'Tiket #12')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $this->assertSame($this->biasa->id, Auth::id());
        $this->assertNotSame($tokenLama, session()->token(), 'token CSRF harus diganti');
        $imp = app(Impersonasi::class);
        $this->assertTrue($imp->aktif());
        $this->assertSame($this->admin->id, $imp->pelakuId());
        $this->assertSame(25, $imp->sisaMenit());

        $log = ImpersonasiLog::sole();
        $this->assertSame('Admin Satu', $log->pelaku_nama);
        $this->assertSame('Laboran Biasa', $log->target_nama);
        $this->assertSame('Tiket #12', $log->alasan);
        $this->assertSame('127.0.0.1', $log->ip);
        $this->assertNull($log->selesai_at);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/akses') && $r['email'] === 'admin@uns.ac.id');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function penolakan(): array
    {
        return [
            'diri sendiri' => ['admin', 'diri sendiri'],
            'sesama admin' => ['admin2', 'dilindungi'],
            'akun cadangan' => ['cadangan', 'dilindungi'],
            'target nonaktif' => ['nonaktif', 'nonaktif'],
        ];
    }

    #[DataProvider('penolakan')]
    public function test_target_yang_ditolak(string $target, string $pesan): void
    {
        $this->actingAs($this->admin)
            ->mulai($this->{$target})
            ->assertSessionHas('error', fn ($e) => str_contains($e, $pesan));

        $this->assertSame($this->admin->id, Auth::id());
        $this->assertSame(0, ImpersonasiLog::count());
    }

    public function test_bukan_admin_ditolak(): void
    {
        $this->actingAs($this->biasa)
            ->mulai($this->nonaktif)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'Hanya admin'));
        Http::assertNothingSent();
    }

    public function test_peran_admin_dicabut_di_eip_ditolak(): void
    {
        $this->peranEip = ['laboran'];

        $this->actingAs($this->admin)
            ->mulai($this->biasa)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'tidak ditemukan di EIP'));
        $this->assertSame($this->admin->id, Auth::id());
    }

    public function test_eip_mati_ditolak(): void
    {
        $this->peranEip = null;

        $this->actingAs($this->admin)
            ->mulai($this->biasa)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'tidak bisa dihubungi'));
        $this->assertSame(0, ImpersonasiLog::count());
    }

    public function test_pelaku_tanpa_email_eip_ditolak(): void
    {
        $this->cadangan->update(['peran' => 'admin']);

        $this->actingAs($this->cadangan)
            ->mulai($this->biasa)
            ->assertSessionHas('error', fn ($e) => str_contains($e, 'tidak terhubung ke EIP'));
    }

    public function test_alasan_wajib(): void
    {
        $this->actingAs($this->admin)->mulai($this->biasa, '')->assertSessionHasErrors('alasan');
        $this->assertSame($this->admin->id, Auth::id());
    }

    public function test_tidak_boleh_berantai(): void
    {
        $this->actingAs($this->admin)->mulai($this->biasa);

        // Route mulai diblokir selama menyamar (middleware), dan kalaupun
        // tidak, periksa() menolak.
        $this->mulai($this->nonaktif)->assertSessionHas('error');
        $this->assertSame($this->biasa->id, Auth::id());
        $this->assertSame(1, ImpersonasiLog::count());
        $this->assertStringContainsString('sedang menyamar', (string) app(Impersonasi::class)->periksa($this->admin, $this->nonaktif));
    }

    public function test_route_diblokir_selama_menyamar(): void
    {
        $this->actingAs($this->admin)->get('/pengguna')->assertOk();

        $this->mulai($this->biasa);
        $this->get('/pengguna')->assertRedirect()->assertSessionHas('error');
        $this->getJson('/pengguna')->assertForbidden();
        $this->get('/laporan')->assertOk()->assertSee('laporan '.$this->biasa->id);
    }

    public function test_kembali_ke_akun_saya(): void
    {
        $this->actingAs($this->admin)->mulai($this->biasa);

        $this->post(route('eip.impersonasi.berhenti'))->assertRedirect(route('dashboard'));

        $this->assertSame($this->admin->id, Auth::id());
        $this->assertFalse(app(Impersonasi::class)->aktif());
        $log = ImpersonasiLog::sole();
        $this->assertSame('kembali', $log->cara_selesai);
        $this->assertNotNull($log->selesai_at);
    }

    public function test_kedaluwarsa_setelah_25_menit(): void
    {
        $this->actingAs($this->admin)->mulai($this->biasa);

        $this->travel(24)->minutes();
        $this->get('/laporan')->assertOk();
        $this->assertSame($this->biasa->id, Auth::id());

        $this->travel(2)->minutes();
        $this->get('/laporan')->assertRedirect(route('dashboard'))->assertSessionHas('success');
        $this->assertSame($this->admin->id, Auth::id());
        $this->assertSame('kedaluwarsa', ImpersonasiLog::sole()->cara_selesai);
    }

    public function test_keluar_saat_menyamar_menutup_semuanya(): void
    {
        $this->actingAs($this->admin)->mulai($this->biasa);

        $this->post('/keluar')->assertRedirect('/');

        $this->assertGuest();
        $this->assertFalse(app(Impersonasi::class)->aktif());
        $this->assertSame('keluar', ImpersonasiLog::sole()->cara_selesai);
    }

    public function test_jejak_tidak_bisa_diubah_atau_dihapus(): void
    {
        $this->actingAs($this->admin)->mulai($this->biasa);
        $log = ImpersonasiLog::sole();

        try {
            $log->update(['alasan' => 'diganti']);
            $this->fail('alasan tidak boleh diubah');
        } catch (LogicException) {
        }

        $this->post(route('eip.impersonasi.berhenti'));
        $log = ImpersonasiLog::sole();
        try {
            $log->update(['cara_selesai' => 'keluar']);
            $this->fail('baris selesai tidak boleh diubah');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_banner_dan_tombol(): void
    {
        $this->actingAs($this->admin);
        $tombol = fn (UjiPengguna $u) => Blade::render('<x-eip-klien::tombol-masuk-sebagai :pengguna="$u" />', ['u' => $u]);

        $this->assertSame('', trim(Blade::render('<x-eip-klien::banner-menyamar />')));
        $this->assertStringContainsString('value="Testing"', $tombol($this->biasa));
        $this->assertStringContainsString('<dialog', $tombol($this->biasa));
        $this->assertStringContainsString('Laboran Biasa', $tombol($this->biasa));
        $this->assertStringContainsString('/pengguna/'.$this->biasa->id.'/masuk-sebagai', $tombol($this->biasa));
        $this->assertSame('', trim($tombol($this->admin2)));
        $this->assertSame('', trim($tombol($this->nonaktif)));

        $this->mulai($this->biasa);
        $banner = Blade::render('<x-eip-klien::banner-menyamar />');
        $this->assertStringContainsString('Laboran Biasa', $banner);
        $this->assertStringContainsString('oleh Admin Satu', $banner);
        $this->assertStringContainsString('Kembali ke akun saya', $banner);

        $riwayat = Blade::render('<x-eip-klien::riwayat-masuk-sebagai />');
        $this->assertStringContainsString('Testing', $riwayat);
        $this->assertStringContainsString('Berlangsung', $riwayat);
        $this->assertStringContainsString('data-label="Alasan"', $riwayat);
    }
}
