<?php

use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Sessione;

// T1.4 (Z3): un 401 del backoffice chiude la sessione e rimanda all'ingresso, con una chiamata sola.

it('un 401 del backoffice chiude la sessione e rimanda all\'ingresso, senza ripetere la chiamata', function () {
    Http::fake(['*' => problema(401, 'gettone_non_valido')]);
    apriSessione();

    $this->get('/io')->assertRedirect(INGRESSO);

    Http::assertSentCount(1);
    expect(session()->has(Sessione::CHIAVE))->toBeFalse()
        ->and(session('url.intended'))->toBe(FRONTEND.'/io');
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

    expect(session('url.intended'))->toBe(FRONTEND.'/pagina');
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

    $this->get('/pagina')->assertRedirect(FRONTEND.'/accedi');
});
