<?php

namespace Fmipa\EipKlien\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;

/** `eip-klien.status.aktif = false`: tanpa route status dan tanpa detak. */
class StatusMatiTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('eip-klien.status.aktif', false);
    }

    public function test_route_dan_detak_tidak_didaftarkan(): void
    {
        $this->assertFalse(Route::has('eip-klien.status'));
        $this->getJson('/eip-klien/status')->assertNotFound();
        $this->assertEmpty(collect(app(Schedule::class)->events())->filter(fn ($e) => $e->description === 'eip-klien:detak'));
    }
}
