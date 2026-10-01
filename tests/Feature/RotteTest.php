<?php

use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Http\Middleware\Sessione;
use Zeiras\Auth\Testing\Rotte;

/*
 * Il test che il modulo eredita (voce #978, T3.7, prova 9 della spec): ogni rotta che risponde senza sessione, fuori
 * dalle tre eccezioni, manda la CI in rosso.
 */

it('le pagine e le API del modulo vogliono la sessione: nessuna rotta scoperta fuori dalle tre eccezioni (T3.7)', function () {
    Route::get('up', fn () => 'su');
    Route::post('auth/avviso', fn () => 'avviso');

    expect(Rotte::senzaSessione())->toBe([]);
});

it('una rotta che si toglie la sessione con withoutMiddleware, o che sta fuori dai gruppi, fa fallire il controllo (T3.7, prova 9)', function () {
    Route::middleware('web')->get('scoperta', fn () => 'aperta')->withoutMiddleware(Sessione::class);
    Route::post('nuda', fn () => 'aperta');

    expect(Rotte::senzaSessione())->toBe(['GET scoperta', 'POST nuda']);
    $this->get('/scoperta')->assertOk()->assertSee('aperta');
    ingressoChiesto($this->get('/pagina'));
});
