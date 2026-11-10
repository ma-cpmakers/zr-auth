<?php

use Illuminate\Support\Carbon;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Testing\BackofficeFinto;

// #2915 (F5.1, F5.4, F5.5): `staff.utenti.modifica` e `staff.utenti.cambio-email.elimina` del finto, per la scheda utente di zr-superadmin. Che forme ed errori siano quelli del backoffice lo prova il
// FintoTest del backoffice; qui si prova che il finto tiene le regole: l'anagrafica corretta, mai la password, l'email che resta in attesa 72 ore e si annulla, la riga di registro coi nomi.

beforeEach(function () {
    Carbon::setTestNow('2026-11-08 10:00:00');
    config(['zr-auth.cliente' => BackofficeFinto::CLIENTE, 'zr-auth.segreto' => BackofficeFinto::SEGRETO_DEL_CLIENTE]);
    request()->server->set('REMOTE_ADDR', '203.0.113.7');
    $this->finto = BackofficeFinto::attiva()->ritorno('pm', 'https://board.zeiras.com/ingresso/ritorno');
});

/** @return array{0: int|null, 1: string|null, 2: string|null} lo stato, il codice e il primo `pointer` di un errore di /v1, o [null, null, null] se riesce. */
function esitoModificaUtente(Closure $chiamata): array
{
    try {
        $chiamata();
    } catch (ErroreApi $errore) {
        return [$errore->stato, $errore->codice, $errore->errori[0]['pointer'] ?? null];
    } catch (GettoneRifiutato $rifiutato) {
        return [$rifiutato->errore?->stato, $rifiutato->errore?->codice, null];
    }

    return [null, null, null];
}

function gettoneModificaUtente(BackofficeFinto $finto, string $ruolo = 'superadmin', string $prefisso = ''): string
{
    $persona = $finto->persona("{$prefisso}{$ruolo}@lucianocastro.com", PASSWORD, nome: ucfirst($ruolo));

    return $finto->ingressoDelloStaff($finto->staffDi($persona, $ruolo, false, false));
}

function emailInAttesa(array $in = []): array
{
    return ['email' => 'nuova.anna@example.org', 'identita_verificata' => true, 'motivo' => 'verifica_account', ...$in];
}

it('corregge l\'anagrafica: 200 con la scheda, paese e nota_staff compresi, una riga di registro coi nomi dei campi e nessun valore (#2915 F5.1)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $registro = count($this->finto->registroDelloStaff());

    $risposta = Api::staff($gettone)->patch("/v1/staff/utenti/{$anna['id']}", ['nome' => 'Anita', 'cognome' => 'Rossi', 'telefono' => '+39 333 1234567', 'lingua' => 'en', 'fuso_orario' => 'Europe/London', 'paese' => 'IT', 'nota_staff' => 'Chiamata di verifica.']);

    expect([$risposta['data']['nome'], $risposta['data']['cognome'], $risposta['data']['telefono'], $risposta['data']['lingua'], $risposta['data']['fuso_orario'], $risposta['data']['paese'], $risposta['data']['nota_staff'], $risposta['data']['cambio_email']])
        ->toBe(['Anita', 'Rossi', '+39 333 1234567', 'en', 'Europe/London', 'IT', 'Chiamata di verifica.', null]);
    $riga = $this->finto->registroDelloStaff()[$registro];
    expect($riga['azione'])->toBe('staff.utenti.modifica')->and($riga['campi'])->toBe(['nome', 'cognome', 'telefono', 'lingua', 'fuso_orario', 'paese', 'nota_staff'])->and($riga['motivo'])->toBeNull()
        ->and(json_encode($riga))->not->toContain('Chiamata');
});

it('un valore uguale a quello di oggi è 200 e non scrive il registro; null toglie i facoltativi e non toglie nome, lingua e fuso (#2915 F5.1)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $percorso = "/v1/staff/utenti/{$anna['id']}";
    $registro = count($this->finto->registroDelloStaff());

    Api::staff($gettone)->patch($percorso, ['nome' => 'Anna', 'paese' => null, 'nota_staff' => null]);
    expect(count($this->finto->registroDelloStaff()))->toBe($registro);

    Api::staff($gettone)->patch($percorso, ['paese' => 'IT', 'nota_staff' => 'x']);
    $risposta = Api::staff($gettone)->patch($percorso, ['paese' => null, 'nota_staff' => null]);

    expect([$risposta['data']['paese'], $risposta['data']['nota_staff']])->toBe([null, null])
        ->and(esitoModificaUtente(fn () => Api::staff($gettone)->patch($percorso, ['nome' => null])))->toBe([422, 'dati_non_validi', '#/nome'])
        ->and(esitoModificaUtente(fn () => Api::staff($gettone)->patch($percorso, ['lingua' => null])))->toBe([422, 'dati_non_validi', '#/lingua'])
        ->and(esitoModificaUtente(fn () => Api::staff($gettone)->patch($percorso, ['fuso_orario' => null])))->toBe([422, 'dati_non_validi', '#/fuso_orario']);
});

it('il corpo che non va è 422 sul pointer e non scrive: vuoto, fuori elenco, password e TOTP (#2915 F5.1)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $percorso = "/v1/staff/utenti/{$anna['id']}";
    $registro = count($this->finto->registroDelloStaff());

    foreach ([[[], '#'], [['lingua' => 'xx'], '#/lingua'], [['paese' => 'it'], '#/paese'], [['telefono' => 'abc'], '#/telefono'], [['nota_staff' => str_repeat('a', 2001)], '#/nota_staff'], ...array_map(fn (string $campo) => [[$campo => 'Nuova prova lunga!'], "#/{$campo}"], ['password', 'totp_segreto'])] as [$corpo, $pointer]) {
        expect(esitoModificaUtente(fn () => Api::staff($gettone)->patch($percorso, $corpo)))->toBe([422, 'dati_non_validi', $pointer]);
    }

    expect(count($this->finto->registroDelloStaff()))->toBe($registro);
});

it('l\'email non cambia subito: resta in attesa 72 ore nella scheda, il registro ha il motivo e mai l\'indirizzo (#2915 F5.5)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $registro = count($this->finto->registroDelloStaff());

    $risposta = Api::staff($gettone)->patch("/v1/staff/utenti/{$anna['id']}", emailInAttesa());

    expect($risposta['data']['email'])->toBe('anna@example.com')
        ->and($risposta['data']['cambio_email'])->toBe(['email' => 'nuova.anna@example.org', 'effettivo_il' => '2026-11-11T10:00:00.000Z', 'motivo' => 'verifica_account']);
    $riga = $this->finto->registroDelloStaff()[$registro];
    expect($riga['campi'])->toBe(['email'])->and($riga['motivo'])->toBe('verifica_account')->and(json_encode($riga))->not->toContain('nuova.anna');
});

it('con email vuole identita_verificata true e un motivo dell\'elenco chiuso; un\'email di un\'altra persona è 422 su #/email (#2915 F5.5)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $this->finto->persona('bea@example.com', PASSWORD, nome: 'Bea');
    $percorso = "/v1/staff/utenti/{$anna['id']}";
    $registro = count($this->finto->registroDelloStaff());

    foreach ([
        [['email' => 'nuova.anna@example.org'], '#/identita_verificata'],
        [emailInAttesa(['identita_verificata' => false]), '#/identita_verificata'],
        [['email' => 'nuova.anna@example.org', 'identita_verificata' => true], '#/motivo'],
        [emailInAttesa(['motivo' => 'perché']), '#/motivo'],
        [emailInAttesa(['email' => 'non-una-email']), '#/email'],
        [emailInAttesa(['email' => 'bea@example.com']), '#/email'],
    ] as [$corpo, $pointer]) {
        expect(esitoModificaUtente(fn () => Api::staff($gettone)->patch($percorso, $corpo)))->toBe([422, 'dati_non_validi', $pointer]);
    }

    expect(count($this->finto->registroDelloStaff()))->toBe($registro)
        ->and(Api::staff($gettone)->get($percorso)['data']['cambio_email'])->toBeNull();
});

it('una richiesta nuova sostituisce quella in attesa; lo staff la annulla con 204 e senza un cambio è 404 (#2915 F5.5)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $percorso = "/v1/staff/utenti/{$anna['id']}";

    expect(esitoModificaUtente(fn () => Api::staff($gettone)->delete("{$percorso}/cambio-email"))[0])->toBe(404);

    Api::staff($gettone)->patch($percorso, emailInAttesa(['email' => 'prima@example.org']));
    $risposta = Api::staff($gettone)->patch($percorso, emailInAttesa(['email' => 'seconda@example.org', 'motivo' => 'sicurezza']));
    expect($risposta['data']['cambio_email']['email'])->toBe('seconda@example.org')->and($risposta['data']['cambio_email']['motivo'])->toBe('sicurezza');

    $registro = count($this->finto->registroDelloStaff());
    Api::staff($gettone)->delete("{$percorso}/cambio-email");

    expect(Api::staff($gettone)->get($percorso)['data']['cambio_email'])->toBeNull()
        ->and($this->finto->registroDelloStaff()[$registro]['azione'])->toBe('staff.utenti.cambio-email.elimina');
});

it('dopo le 72 ore l\'email cambia e non è verificata: la password da sola non basta, e prima delle 72 ore non cambia niente (#2915 F5.5)', function () {
    $gettone = gettoneModificaUtente($this->finto);
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $percorso = "/v1/staff/utenti/{$anna['id']}";
    Api::staff($gettone)->patch($percorso, emailInAttesa());

    Carbon::setTestNow('2026-11-11 09:59:00');
    $this->finto->applicaICambiEmailDelloStaff();
    expect(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->status())->toBe(201);

    Carbon::setTestNow('2026-11-11 10:00:01');
    $nuovo = gettoneModificaUtente($this->finto, 'superadmin', 'secondo.');
    $scheda = Api::staff($nuovo)->get($percorso)['data'];

    expect([$scheda['email'], $scheda['cambio_email']])->toBe(['nuova.anna@example.org', null])
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'nuova.anna@example.org', 'password' => PASSWORD])->json('codice'))->toBe('credenziali_non_valide')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('codice'))->toBe('credenziali_non_valide');
});

it('è del solo superadmin: supporto e fatturazione sono 403, un utente che non c\'è 404, una persona dello staff 403 (#2915)', function () {
    $anna = $this->finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $superadmin = gettoneModificaUtente($this->finto);
    $gettoni = ['supporto' => gettoneModificaUtente($this->finto, 'supporto'), 'fatturazione' => gettoneModificaUtente($this->finto, 'fatturazione')];
    $registro = count($this->finto->registroDelloStaff());

    foreach ($gettoni as $gettone) {
        expect(esitoModificaUtente(fn () => Api::staff($gettone)->patch("/v1/staff/utenti/{$anna['id']}", ['nome' => 'Altro'])))->toBe([403, 'permesso_negato', null])
            ->and(esitoModificaUtente(fn () => Api::staff($gettone)->delete("/v1/staff/utenti/{$anna['id']}/cambio-email"))[0])->toBe(403);
    }

    expect(esitoModificaUtente(fn () => Api::staff($superadmin)->patch('/v1/staff/utenti/01k8x5c9y7f2r6q0s4t8v2w6z0', ['nome' => 'Altro']))[0])->toBe(404)
        ->and(count($this->finto->registroDelloStaff()))->toBe($registro);
});
