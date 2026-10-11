<?php

use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\AccettazioneRichiesta;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Sessione;

// T5 (#1744, #1699): davanti al 403 `accettazione_richiesta` il client manda la persona alla pagina dell'accettazione di zr-home,
// con il `ritorno`; accettato, zr-home la rimanda indietro. Il resto dei 403 è com'era.

const ACCETTAZIONE = 'https://app.zeiras.com/legale/accetta';
const MANCANTI = [['documento' => 'termini-d-uso', 'versione' => '2026-10']];

/** Il backoffice risponde 403 accettazione_richiesta a ogni chiamata. */
function accettazioneRichiesta(): void
{
    Http::fake(['*' => problema(403, 'accettazione_richiesta', ['mancanti' => MANCANTI])]);
}

beforeEach(function () {
    config(['zr-auth.accettazione' => ACCETTAZIONE]);
    apriSessione();
});

it('una pagina in GET manda alla pagina dell\'accettazione con il ritorno, e la sessione resta aperta (T5.1)', function () {
    accettazioneRichiesta();
    $this->get(FRONTEND.'/io?x=1')->assertRedirect(ACCETTAZIONE.'?ritorno='.rawurlencode(FRONTEND.'/io?x=1'));

    expect(session()->has(Sessione::CHIAVE))->toBeTrue();
    Http::assertSentCount(1);
});

it('da un POST il ritorno è la radice del frontend, mai la pagina del POST (T5.2)', function () {
    accettazioneRichiesta();
    $this->post(FRONTEND.'/scrive')->assertRedirect(ACCETTAZIONE.'?ritorno='.rawurlencode(FRONTEND));
});

it('un ritorno oltre i 2000 caratteri diventa la radice (T5.3)', function () {
    accettazioneRichiesta();
    $this->get(FRONTEND.'/io?x='.str_repeat('a', 2100))->assertRedirect(ACCETTAZIONE.'?ritorno='.rawurlencode(FRONTEND));
});

it('una richiesta JSON riceve 403 con il codice e i mancanti, una visita di Inertia 409 con X-Inertia-Location (T5.4)', function () {
    accettazioneRichiesta();
    $this->getJson('/io')->assertForbidden()->assertExactJson(['codice' => 'accettazione_richiesta', 'mancanti' => MANCANTI]);
    $this->get(FRONTEND.'/io', ['X-Inertia' => 'true'])->assertStatus(409)
        ->assertHeader('X-Inertia-Location', ACCETTAZIONE.'?ritorno='.rawurlencode(FRONTEND.'/io'));
});

it('la pagina dell\'accettazione non rimanda a sé stessa, anche con la lingua davanti (T5.5, deve fallire se c\'è un ciclo)', function () {
    accettazioneRichiesta();
    config(['zr-auth.accettazione' => FRONTEND.'/legale/accetta']);

    $this->get(FRONTEND.'/legale/accetta')->assertForbidden()->assertJsonPath('codice', 'accettazione_richiesta');
    $this->get(FRONTEND.'/it/legale/accetta')->assertForbidden()->assertJsonPath('codice', 'accettazione_richiesta');
});

it('un altro 403 resta un ErroreApi che nessuno rimanda: il codice dice la cosa giusta (T5.6)', function () {
    Http::fake(['*' => problema(403, 'permesso_negato')]);

    expect(fn () => Api::workspace()->get('/v1/io'))
        ->toThrow(fn (ErroreApi $e) => expect($e)->not->toBeInstanceOf(AccettazioneRichiesta::class)->and($e->codice)->toBe('permesso_negato'));
});

it('chi lo cattura lo trova come ErroreApi, con il codice e i mancanti (T5.7)', function () {
    accettazioneRichiesta();
    expect(fn () => Api::workspace()->get('/v1/io'))
        ->toThrow(fn (ErroreApi $e) => expect($e)->toBeInstanceOf(AccettazioneRichiesta::class)
            ->and($e->stato)->toBe(403)->and($e->codice)->toBe('accettazione_richiesta')->and($e->estensioni['mancanti'])->toBe(MANCANTI));
});
