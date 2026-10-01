<?php

use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Http\Controllers\RitornoController;
use Zeiras\Auth\Http\Middleware\Sessione;

// Il ritorno da zr-home: vuole la sessione di Laravel, dove aspetta l'ingresso, non quella del modulo, che nasce qui.
Route::middleware('web')
    ->get('auth/callback', RitornoController::class)
    ->withoutMiddleware(Sessione::class)
    ->name('zr-auth.callback');
