<?php

use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Persona;
use Zeiras\Auth\Sessione;

// #2136: la difesa comune «Resta poi Salva». Una scrittura partita da una scheda rimasta ferma su A, dopo che B è entrato nello
// stesso browser (la stessa sessione), non passa col nome di B. La pagina dice di chi è con `X-Zr-Persona` (Persona::impronta()).

const ALTRA_PERSONA = '01k6r2t5b9d3f7h1k5m9n3q7r2';

/** Il browser è lo stesso e B entra: la sessione di A diventa quella di B (Sessione::apri rigenera l'id e riscrive la persona). */
function entraAltraPersona(): void
{
    $accesso = accesso();
    $accesso['gettone']['utente'] = [...utente(), 'id' => ALTRA_PERSONA, 'nome' => 'Bruno', 'email' => 'bruno@example.com'];
    Sessione::apri($accesso);
}

function scritture(): int
{
    return app()->bound('persona.scritture') ? app('persona.scritture') : 0;
}

beforeEach(function () {
    config(['zr-auth.persona.controllo' => true]);
});

// T1.1
it('l\'impronta è la stessa per la stessa persona a ogni ingresso, diversa per due persone, e non è né l\'id né l\'email', function () {
    apriSessione();
    $anna = Persona::impronta();
    apriSessione(false);

    expect($anna)->toMatch('/\A[0-9a-f]{32}\z/')
        ->and(Persona::impronta())->toBe($anna)
        ->and($anna)->not->toContain(utente()['id'])->not->toContain('anna');

    entraAltraPersona();

    expect(Persona::impronta())->toMatch('/\A[0-9a-f]{32}\z/')->not->toBe($anna);
});

it('senza sessione l\'impronta è null', function () {
    expect(Persona::impronta())->toBeNull();
});

// T1.2
it('una scrittura con l\'impronta della sessione arriva alla rotta, con ogni verbo che scrive', function (string $verbo) {
    apriSessione();

    $this->call($verbo, '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => Persona::impronta()])->assertOk();

    expect(scritture())->toBe(1);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

// T1.3
it('«Resta poi Salva»: la scheda di A scrive dopo l\'ingresso di B, 409 e la rotta non gira, nessuna chiamata al backoffice', function () {
    Http::fake();
    apriSessione();
    $diAnna = Persona::impronta();
    entraAltraPersona();

    $this->postJson('/persona/scrive', [], ['X-Zr-Persona' => $diAnna])
        ->assertStatus(409)
        ->assertExactJson(['errore' => 'persona_diversa']);

    expect(scritture())->toBe(0);
    Http::assertNothingSent();
});

// T1.4
it('senza l\'intestazione, o con l\'intestazione vuota, la scrittura è rifiutata allo stesso modo', function (?string $valore) {
    apriSessione();

    $this->postJson('/persona/scrive', [], $valore === null ? [] : ['X-Zr-Persona' => $valore])
        ->assertStatus(409)
        ->assertExactJson(['errore' => 'persona_diversa']);

    expect(scritture())->toBe(0);
})->with([null, '']);

// T1.5
it('un\'intestazione ripetuta vale per tutte: una sbagliata dopo la giusta è 409', function () {
    apriSessione();

    $this->call('POST', '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => Persona::impronta().', '.str_repeat('0', 32)])->assertStatus(409);
    $this->call('POST', '/persona/scrive', [], [], [], ['HTTP_X_ZR_PERSONA' => str_repeat('0', 32).', '.Persona::impronta()])->assertStatus(409);

    expect(scritture())->toBe(0);
});

// T1.6
it('il 409 non porta l\'impronta, l\'email né l\'id di nessuna delle due persone', function () {
    apriSessione();
    $diAnna = Persona::impronta();
    entraAltraPersona();

    $risposta = $this->postJson('/persona/scrive', [], ['X-Zr-Persona' => $diAnna])->assertStatus(409);

    $tutto = strtolower($risposta->baseResponse->headers->__toString().$risposta->getContent());
    foreach ([$diAnna, Persona::impronta(), utente()['id'], ALTRA_PERSONA, 'anna@example.com', 'bruno@example.com'] as $segreto) {
        expect($tutto)->not->toContain(strtolower((string) $segreto));
    }
});

// T1.7
it('GET, HEAD e OPTIONS passano senza l\'intestazione', function (string $verbo) {
    apriSessione();

    $this->call($verbo, '/persona/legge')->assertOk();
})->with(['GET', 'HEAD']);

it('OPTIONS non è controllato', function () {
    apriSessione();

    expect($this->call('OPTIONS', '/persona/scrive')->getStatusCode())->not->toBe(409);
});

// T1.8
it('una rotta che si toglie il controllo («Esci») passa anche con l\'impronta di un\'altra persona', function () {
    apriSessione();
    $diAnna = Persona::impronta();
    entraAltraPersona();

    $this->postJson('/persona/esci', [], ['X-Zr-Persona' => $diAnna])->assertOk();
    $this->postJson('/persona/esci')->assertOk();
});

// T1.9
it('le impronte si confrontano a tempo costante', function () {
    $sorgente = file_get_contents(__DIR__.'/../../src/Http/Middleware/SoloDallaPersona.php');

    expect($sorgente)->toContain('hash_equals(')
        ->and($sorgente)->not->toMatch('/(===|!==|==|!=)\s*\$(impronta|detta|sua|attesa)\b/')
        ->and($sorgente)->not->toMatch('/\$(impronta|detta|sua|attesa)\b\s*(===|!==|==|!=)\s*\$/');
});

// T1.10
it('senza sessione la strada è quella della guardia, non il 409', function () {
    $this->postJson('/persona/scrive', [], ['X-Zr-Persona' => str_repeat('0', 32)])->assertStatus(401);
    $this->post('/persona/scrive')->assertRedirect(INGRESSO);
});

it('col controllo spento (il default) una scrittura senza intestazione passa come prima', function () {
    config(['zr-auth.persona.controllo' => false]);
    apriSessione();

    $this->postJson('/persona/scrive')->assertOk();

    expect(scritture())->toBe(1)
        ->and((new ReflectionClass(Persona::class))->hasMethod('impronta'))->toBeTrue()
        ->and(require __DIR__.'/../../config/zr-auth.php')->toHaveKey('persona.controllo', false);
});

// T1.11
it('il README dice come passa ciascun modulo alla comune e che «Esci» è esente', function () {
    $readme = file_get_contents(__DIR__.'/../../README.md');
    preg_match('/^## La stessa persona[^\n]*\n(.*?)(?=^## |\z)/ms', $readme, $trovata);
    $sezione = $trovata[1] ?? '';

    expect($sezione)->not->toBe('')
        ->toContain('X-Zr-Persona')
        ->toContain('persona.controllo')
        ->toContain('persona_diversa')
        ->toContain('Persona::impronta()')
        ->toContain('SoloDallaPersona')
        ->toContain('zr-home')
        ->toContain('zr-board')
        ->toContain('zr-crm')
        ->toContain('Esci');
});
