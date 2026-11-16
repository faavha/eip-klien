<?php

namespace Fmipa\EipKlien\Tests;

use Fmipa\EipKlien\Http\JagaImpersonasi;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/** Bawaan: impersonasi mati — tidak ada route, middleware, atau tombol. */
class ImpersonasiMatiTest extends TestCase
{
    public function test_mati_bawaan(): void
    {
        $this->assertFalse(config('eip-klien.impersonasi.aktif'));
        $this->assertFalse(Route::has('eip.impersonasi.mulai'));
        $this->assertNotContains(JagaImpersonasi::class, app(Router::class)->getMiddlewareGroups()['web'] ?? []);
        $this->assertSame('', trim(Blade::render('<x-eip-klien::banner-menyamar />')));
    }

    /** Migrasi tetap terdaftar walau mati, supaya `migrate` tidak bergantung cache config. */
    public function test_migrasi_selalu_terdaftar(): void
    {
        $paths = array_map('realpath', app('migrator')->paths());

        $this->assertContains(realpath(__DIR__.'/../database/migrations'), $paths);
    }
}
