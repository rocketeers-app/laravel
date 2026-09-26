<?php

use Illuminate\Support\Facades\Route;
use Rocketeers\Laravel\Http\Controllers\HorizonStatsController;
use Rocketeers\Laravel\Http\Middleware\VerifyHorizonSignature;
use Rocketeers\Laravel\Support\HorizonSignature;

// Registered outside the `web` group on purpose: no session, no cookies and no
// CSRF token are involved, so nothing here can be driven by a visitor's browser
// session. The signature is the only credential.
Route::get(HorizonSignature::PATH, HorizonStatsController::class)
    ->middleware(VerifyHorizonSignature::class)
    ->name('rocketeers.horizon.stats');
