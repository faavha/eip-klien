<?php

use Fmipa\EipKlien\Http\EipLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware(config('eip-klien.sso.middleware', ['web', 'guest']))->group(function () {
    Route::get('/auth/eip', [EipLoginController::class, 'redirect'])->name('auth.eip');
    Route::get('/auth/eip/callback', [EipLoginController::class, 'callback'])->name('auth.eip.callback');
});
