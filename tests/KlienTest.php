<?php

namespace Fmipa\EipKlien\Tests;

use Fmipa\EipKlien\Contracts\PeranLokal;
use Fmipa\EipKlien\EipClient;
use Fmipa\EipKlien\PengalihAplikasi;
use Fmipa\EipKlien\PeranEip;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;

class KlienTest extends TestCase
{
    public function test_paginasi_meta_dan_links_diikuti(): void
    {
        Http::fake([
            'eip.test/api/v1/pegawai?*' => Http::sequence()
                ->push(['data' => [['uid' => 'a']], 'meta' => ['last_page' => 2]])
                ->push(['data' => [['uid' => 'b']], 'meta' => ['last_page' => 2]]),
            'eip.test/api/v1/unit-kerja?*' => Http::sequence()
                ->push(['data' => [['kode' => 'X']], 'links' => ['next' => 'n']])
                ->push(['data' => [['kode' => 'Y']], 'links' => ['next' => null]]),
        ]);

        $eip = app(EipClient::class);
        $this->assertSame(['a', 'b'], array_column($eip->pegawaiList(['is_active' => 1]), 'uid'));
        $this->assertSame(['X', 'Y'], array_column($eip->unitKerjaList(['status' => 'all']), 'kode'));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'is_active=1') && $r->hasHeader('Authorization', 'Bearer token-uji'));
    }

    public function test_akses_memakai_kode_aplikasi_dan_404_jadi_null(): void
    {
        Http::fake([
            'eip.test/api/v1/akses?*' => fn ($r) => str_contains($r->url(), 'bukan%40')
                ? Http::response([], 404)
                : Http::response(['data' => ['boleh_masuk' => true, 'peran' => [['kode' => 'admin', 'unit_kerja' => [['id' => 3, 'kode' => 'FAK']]]]]]),
        ]);

        $eip = app(EipClient::class);
        $this->assertNull($eip->akses('bukan@x.id'));
        $peran = PeranEip::dariAkses($eip->akses('ada@x.id'));
        $this->assertSame([3], $peran->unitUntuk('admin'));
        $this->assertSame('admin', $peran->utama(['superadmin', 'admin']));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'aplikasi=uji'));
    }

    public function test_pemegang_digabung_per_orang(): void
    {
        Http::fake([
            'eip.test/api/v1/akses/pemegang?*' => fn ($r) => str_contains($r->url(), 'peran=admin')
                ? Http::response(['data' => [['uid' => 'u1', 'nama' => 'A', 'email' => 'A@x.id', 'unit_kerja' => []]]])
                : Http::response(['data' => [['uid' => 'u1', 'nama' => 'A', 'email' => 'A@x.id', 'unit_kerja' => [['id' => 9]]]]]),
        ]);

        $orang = app(EipClient::class)->pemegangPerOrang(['admin', 'laboran']);
        $this->assertSame(['admin', 'laboran'], $orang['u1']['peran']);
        $this->assertSame('a@x.id', $orang['u1']['email']);
        $this->assertSame([['id' => 9]], $orang['u1']['unit_kerja']['laboran']);
    }

    public function test_pengalih_aplikasi_dari_eip_dan_cadangan(): void
    {
        config(['eip-klien.pengalih.minimal' => [['kode' => 'uji', 'nama' => 'Aplikasi Uji']]]);
        Http::fake([
            'eip.test/api/v1/pegawai?*' => Http::response(['data' => [['uid' => 'u1']]]),
            'eip.test/api/v1/pegawai/u1/aplikasi' => Http::response(['data' => [
                ['kode' => 'strap', 'nama' => 'STRAP', 'url' => 'https://strap.test'],
                ['kode' => 'rusak'],
            ]]),
        ]);
        $pengalih = app(PengalihAplikasi::class);

        $daftar = $pengalih->untuk(new GenericUser(['id' => 1, 'email' => 'a@x.id']));
        $this->assertSame(['strap'], array_column($daftar, 'kode'));
        $this->assertSame('#64748b', $daftar[0]['warna']);
    }

    public function test_pengalih_aplikasi_cadangan_bila_eip_mati(): void
    {
        config(['eip-klien.pengalih.minimal' => [['kode' => 'uji', 'nama' => 'Aplikasi Uji']]]);
        Http::fake(['*' => Http::response([], 500)]);
        $cadangan = app(PengalihAplikasi::class)->untuk(new GenericUser(['id' => 2, 'email' => 'b@x.id']));
        $this->assertSame(['eip', 'uji'], array_column($cadangan, 'kode'));
        $this->assertSame(self::ISSUER, $cadangan[0]['url']);
    }

    public function test_banding_peran(): void
    {
        $this->app->instance(PeranLokal::class, new class implements PeranLokal
        {
            public function kodePeran(): array
            {
                return ['admin', 'pengaju'];
            }

            public function akun(): iterable
            {
                yield 'sama@x.id' => ['admin'];
                yield 'hilang@x.id' => ['admin', 'pengaju'];
                yield 'luar@x.id' => ['admin'];
            }
        });
        Http::fake([
            'eip.test/api/v1/pegawai?*' => fn ($r) => Http::response(['data' => str_contains($r->url(), 'luar') ? [] : [['uid' => 'u']]]),
            'eip.test/api/v1/akses?*' => Http::response(['data' => ['peran' => [['kode' => 'admin', 'unit_kerja' => []]]]]),
        ]);

        $this->artisan('eip:banding-peran')
            ->expectsOutputToContain('HILANG: pengaju')
            ->expectsOutputToContain('bukan pegawai EIP')
            ->doesntExpectOutputToContain('sama@x.id')
            ->assertSuccessful();
    }

    public function test_banding_peran_tanpa_peran_lokal_gagal_jelas(): void
    {
        $this->artisan('eip:banding-peran')->assertFailed();
    }
}
