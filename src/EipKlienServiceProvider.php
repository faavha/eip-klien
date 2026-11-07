<?php

namespace Fmipa\EipKlien;

use Fmipa\EipKlien\Console\BandingPeranCommand;
use Illuminate\Support\ServiceProvider;

class EipKlienServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/eip-klien.php', 'eip-klien');

        $this->app->singleton(EipClient::class, fn () => EipClient::dariConfig());
        $this->app->singleton(EipSso::class);
        $this->app->singleton(PengalihAplikasi::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/eip-klien.php' => config_path('eip-klien.php'),
        ], 'eip-klien-config');

        if (config('eip-klien.sso.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/eip-klien.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([BandingPeranCommand::class]);
        }
    }
}
