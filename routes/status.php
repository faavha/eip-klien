<?php

use Fmipa\EipKlien\Http\StatusController;
use Illuminate\Support\Facades\Route;

// Tanpa sesi/CSRF (bukan grup `web`): dipanggil server EIP, bukan peramban.
Route::middleware(config('eip-klien.status.middleware', ['throttle:30,1']))
    ->get('/eip-klien/status', StatusController::class)
    ->name('eip-klien.status');
