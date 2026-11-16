<?php

use Fmipa\EipKlien\Http\ImpersonasiController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('eip-klien.impersonasi.middleware', ['web', 'auth']))->group(function () {
    Route::post(config('eip-klien.impersonasi.path_mulai', '/pengguna/{pengguna}/masuk-sebagai'), [ImpersonasiController::class, 'mulai'])
        ->name('eip.impersonasi.mulai');
    Route::post(config('eip-klien.impersonasi.path_berhenti', '/berhenti-menyamar'), [ImpersonasiController::class, 'berhenti'])
        ->name('eip.impersonasi.berhenti');
});
