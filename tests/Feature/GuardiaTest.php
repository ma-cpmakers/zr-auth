<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Zeiras\Auth\Sessione;

// T1.4 (Z3): un 401 del backoffice chiude la sessione e rimanda all'ingresso, con una chiamata sola.

it('un 401 del backoffice chiude la sessione e rimanda all\'ingresso, senza ripetere la chiamata', function () {
    Http::fake(['*' => problema(401, 'gettone_non_valido')]);
    apriSessione();

    $this->get('/io')->assertRedirect(INGRESSO);

    Http::assertSentCount(1);
    expect(session()->has(Sessione::CHIAVE))->toBeFalse()
        ->and(session('url.intended'))->toBe(url('/io'));
});

it('a una visita di Inertia il 401 risponde 409 con X-Inertia-Location', function () {
    Http::fake(['*' => problema(401, 'gettone_non_valido')]);
    apriSessione();

    $this->get('/io', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', INGRESSO);

    Http::assertSentCount(1);
    expect(session()->has(Sessione::CHIAVE))->toBeFalse();
});

// T1.5 (Z4): la guardia in ogni rotta del gruppo `web`.

it('senza sessione una pagina rimanda all\'ingresso e ricorda dove voleva andare', function () {
    $this->get('/pagina')->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe(url('/pagina'));
});

it('senza sessione una richiesta JSON riceve 401, e una visita di Inertia 409', function () {
    $this->getJson('/pagina')->assertStatus(401);
    $this->get('/pagina', ['X-Inertia' => 'true'])->assertStatus(409)->assertHeader('X-Inertia-Location', INGRESSO);
});

it('col gettone scaduto rimanda all\'ingresso, e il gettone esce dalla sessione', function () {
    apriSessione(scadeIl: now()->subSecond()->toJSON());

    $this->get('/pagina')->assertRedirect(INGRESSO);

    expect(session()->has(Sessione::CHIAVE))->toBeFalse();
});

it('con la sessione aperta la pagina risponde', function () {
    apriSessione();

    $this->get('/pagina')->assertOk();
});

it('l\'ingresso si sceglie con ZR_AUTH_INGRESSO', function () {
    config(['zr-auth.ingresso' => '/accedi']);

    $this->get('/pagina')->assertRedirect(url('/accedi'));
});

// T6.1 (ZB1): il ritorno si ricorda solo da un GET, ed è l'indirizzo della richiesta; mai il Referer.

it('senza sessione un GET ricorda l\'indirizzo della richiesta, non il Referer', function () {
    $this->get(FRONTEND.'/pagina?vista=2', ['Referer' => 'https://altro.zeiras.com/x'])->assertRedirect(INGRESSO);

    expect(session('url.intended'))->toBe('https://board.zeiras.com/pagina?vista=2');
});

it('senza sessione HEAD, POST, PUT, PATCH e DELETE rimandano all\'ingresso e non ricordano niente, nemmeno il Referer', function (string $metodo) {
    Route::middleware('web')->any('modulo', fn () => 'ok');

    $this->call($metodo, FRONTEND.'/modulo', [], [], [], ['HTTP_REFERER' => 'https://altro.zeiras.com/x'])
        ->assertRedirect(INGRESSO);

    expect(session()->has('url.intended'))->toBeFalse();
})->with(['HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']);
