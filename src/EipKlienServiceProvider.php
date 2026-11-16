<?php

namespace Fmipa\EipKlien;

use Fmipa\EipKlien\Console\BandingPeranCommand;
use Fmipa\EipKlien\Http\JagaImpersonasi;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\ServiceProvider;

class EipKlienServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eip-klien.php', 'eip-klien');

        $this->app->singleton(EipClient::class, fn () => EipClient::dariConfig());
        $this->app->singleton(EipSso::class);
        $this->app->singleton(PengalihAplikasi::class);
        $this->app->singleton(Impersonasi::class);
        $this->app->singleton(KunciEip::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eip-klien.php' => config_path('eip-klien.php'),
        ], 'eip-klien-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/eip-klien'),
        ], 'eip-klien-views');

        // Komponen <x-eip-klien::...> (aman dipakai walau impersonasi mati).
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'eip-klien');

        if (config('eip-klien.sso.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/eip-klien.php');
        }

        // Selalu didaftarkan (tabel kosong tidak berbahaya): bila didaftarkan
        // hanya saat `aktif`, `migrate` dengan cache config lama melewatkannya.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('eip-klien.status.aktif', true)) {
            $this->bootStatus();
        }

        if (config('eip-klien.impersonasi.aktif', false)) {
            $this->bootImpersonasi();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([BandingPeranCommand::class]);
        }
    }

    /** Endpoint status (Peta Sistem EIP) + detak scheduler. */
    private function bootStatus(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/status.php');

        if (! config('eip-klien.status.detak', true)) {
            return;
        }

        // Hanya berjalan saat Schedule dibuat (schedule:run/list); aplikasi
        // tanpa scheduler tidak terpengaruh. Callback ringan, bernama sendiri,
        // tanpa withoutOverlapping, jadi tidak berbagi mutex dengan tugas lain.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => app(PengumpulStatus::class)->catatDetak())
                ->everyMinute()
                ->name('eip-klien:detak');
        });
    }

    /** "Masuk sebagai" (ADR 0019) — hanya bila aplikasi menyalakannya. */
    private function bootImpersonasi(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/impersonasi.php');

        // Lewat Kernel, bukan Router: Kernel menyalin ulang grupnya ke Router
        // saat pertama di-resolve, sehingga tambahan langsung ke Router bisa hilang.
        /** @var HttpKernel $kernel */
        $kernel = $this->app->make(HttpKernelContract::class);
        $kernel->appendMiddlewareToGroup('web', JagaImpersonasi::class);
    }
}
