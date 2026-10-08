<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// T1.1-T1.6 dello sprint 10 (#1346; per zr-home #1210, DoD G13): la password, gli ingressi nei moduli e l'attivazione
// delle app nel finto. Qui gli aiuti e gli invarianti che un modulo vede dal suo lato; che le risposte siano quelle del
// backoffice, byte per byte, lo prova il FintoTest del backoffice (G12, R25).

const RITORNO_DI_PM = 'https://board.zeiras.com/ingresso/ritorno';

const PASSWORD_NUOVA = 'una password nuova e sicura';

/** Una persona verificata con un workspace suo e `pm` attiva, e l'indirizzo di ritorno di `pm`. [finto, workspace] */
function fintoConPm(): array
{
    $finto = BackofficeFinto::attiva()->ritorno('pm', RITORNO_DI_PM);
    $anna = $finto->persona('anna@example.com', PASSWORD);
    $studio = $finto->workspace('Studio', $anna);
    $finto->attivaApp($studio, 'pm');

    return [$finto, $studio];
}

/** La sfida di un verificatore: base64url senza padding di SHA-256. */
function sfidaDi(string $verificatore): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verificatore, true)), '+/', '-_'), '=');
}

it("attivaApp() e ritorno() rifiutano ciò che il backoffice non conosce: un workspace che non è del finto, un'app in arrivo o fuori catalogo (T1.3, T1.4)", function () {
    $finto = BackofficeFinto::attiva();
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));

    expect(fn () => $finto->attivaApp(['id' => 'inventato'], 'pm'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->attivaApp($studio, 'crm'))->toThrow(InvalidArgumentException::class, 'crm')
        ->and(fn () => $finto->attivaApp($studio, 'inesistente'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $finto->ritorno('inesistente', RITORNO_DI_PM))->toThrow(InvalidArgumentException::class);
});

it("app.elenca dice `attivo` per l'app che il workspace ha attivato, e app.modifica la attiva e la spegne come il proprietario (T1.3)", function () {
    $finto = BackofficeFinto::attiva();
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $stato = fn () => collect(alFinto('GET', '/v1/app', gettone: $gettone)->json('data'))->firstWhere('codice', 'pm')['stato'];

    expect($stato())->toBe('disponibile')
        ->and(alFinto('PATCH', '/v1/app/pm', ['stato' => 'attivo'], $gettone)->json('data.stato'))->toBe('attivo')
        ->and($stato())->toBe('attivo')
        ->and(alFinto('PATCH', '/v1/app/pm', ['stato' => 'disponibile'], $gettone)->json('data.stato'))->toBe('disponibile')
        ->and($stato())->toBe('disponibile')
        // Un'app in arrivo non si attiva.
        ->and(alFinto('PATCH', '/v1/app/crm', ['stato' => 'attivo'], $gettone)->json('codice'))->toBe('app_in_arrivo');
});

it("app.modifica non è di un membro: 403 permesso_negato prima dell'app e del corpo (T1.3)", function () {
    $finto = BackofficeFinto::attiva();
    $studio = $finto->workspace('Studio', $finto->persona('anna@example.com', PASSWORD));
    $finto->membro($studio, $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno'), 'membro');
    $accesso = entraNelFinto('bruno@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');

    foreach ([['/v1/app/pm', ['stato' => 'attivo']], ['/v1/app/inesistente', ['campo' => 'in più']]] as [$percorso, $corpo]) {
        $risposta = alFinto('PATCH', $percorso, $corpo, $gettone);

        expect($risposta->status())->toBe(403)->and($risposta->json('codice'))->toBe('permesso_negato');
    }

    // Il gettone dell'accesso non ha workspace.
    expect(alFinto('PATCH', '/v1/app/pm', ['stato' => 'attivo'], $accesso)->json('codice'))->toBe('gettone_senza_workspace');
});

it('il recupero manda il codice solo a un account, e la reimpostazione cambia la password e chiude i gettoni di prima (T1.1, T1.2)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $gettone = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $conAccount = alFinto('POST', '/v1/password/recupero', ['email' => ' Anna@Example.com ']);
    $senzaAccount = alFinto('POST', '/v1/password/recupero', ['email' => 'nessuno@example.com']);
    $codice = $finto->ultimoCodice('anna@example.com');

    expect($conAccount->status())->toBe(202)
        ->and($conAccount->json())->toBe(['data' => ['email' => 'anna@example.com']])
        ->and($senzaAccount->status())->toBe(202)
        ->and($senzaAccount->json())->toBe(['data' => ['email' => 'nessuno@example.com']])
        ->and($codice)->toMatch('/^[0-9]{6}$/')
        ->and($finto->ultimoCodice('nessuno@example.com'))->toBeNull();

    $reimpostata = alFinto('POST', '/v1/password/reimpostazione', ['email' => 'anna@example.com', 'codice' => $codice, 'password' => PASSWORD_NUOVA]);

    expect($reimpostata->status())->toBe(204)
        ->and(alFinto('GET', '/v1/io', gettone: $gettone)->json('codice'))->toBe('gettone_non_valido')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD])->json('codice'))->toBe('credenziali_non_valide')
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_NUOVA])->status())->toBe(201)
        // Una volta sola.
        ->and(alFinto('POST', '/v1/password/reimpostazione', ['email' => 'anna@example.com', 'codice' => $codice, 'password' => PASSWORD_NUOVA])->json('codice'))
        ->toBe('verifica_non_riuscita');
});

it('il codice di recupero non è quello di verifica: il primo non verifica la persona, il secondo non reimposta (T1.2)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD, verificata: false);
    $codiceDiVerifica = $finto->ultimoCodice('anna@example.com');

    expect(alFinto('POST', '/v1/password/reimpostazione', ['email' => 'anna@example.com', 'codice' => $codiceDiVerifica, 'password' => PASSWORD_NUOVA])->json('codice'))
        ->toBe('verifica_non_riuscita');

    alFinto('POST', '/v1/password/recupero', ['email' => 'anna@example.com']);
    $codiceDiRecupero = $finto->ultimoCodice('anna@example.com');

    expect($codiceDiRecupero)->not->toBe($codiceDiVerifica)
        ->and(alFinto('POST', '/v1/password/reimpostazione', ['email' => 'anna@example.com', 'codice' => $codiceDiRecupero, 'password' => PASSWORD_NUOVA])->status())->toBe(204)
        // Reimpostare la password prova l'email: da lì si entra.
        ->and(alFinto('POST', '/v1/accessi', ['email' => 'anna@example.com', 'password' => PASSWORD_NUOVA])->json('data.gettone.utente.email_verificata_il'))->toBeString();
});

it("un'app attiva dà il codice di un ingresso, con il ritorno che il test ha detto e mai quello della richiesta, e lo scambio dà il gettone del workspace (T1.4, T1.5)", function () {
    [, $studio] = fintoConPm();
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso)->json('data.gettone');
    $verificatore = str_repeat('v', 64);

    $ingresso = alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone);

    expect($ingresso->status())->toBe(201)
        ->and($ingresso->json('data.ritorno'))->toBe(RITORNO_DI_PM)
        ->and($ingresso->json('data.codice'))->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore), 'ritorno' => 'https://evil.example/'], $gettone)->json('errors.0.pointer'))->toBe('#/ritorno');

    $scambio = alFinto('POST', '/v1/ingressi/scambio', ['codice' => $ingresso->json('data.codice'), 'verificatore' => $verificatore]);

    expect($scambio->status())->toBe(201)
        ->and($scambio->json('data.workspace.id'))->toBe($studio['id'])
        ->and($scambio->json('data.ruolo'))->toBe('proprietario')
        ->and(alFinto('GET', '/v1/io', gettone: $scambio->json('data.gettone'))->json('data.workspace.id'))->toBe($studio['id'])
        // Il codice vale una volta.
        ->and(alFinto('POST', '/v1/ingressi/scambio', ['codice' => $ingresso->json('data.codice'), 'verificatore' => $verificatore])->json('codice'))->toBe('verifica_non_riuscita');
});

it("un verificatore sbagliato consuma il codice, e l'ingresso a un'app spenta o senza ritorno non nasce (T1.4, T1.5)", function () {
    [$finto, $studio] = fintoConPm();
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $verificatore = str_repeat('v', 64);
    $codice = alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone)->json('data.codice');

    expect(alFinto('POST', '/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => str_repeat('w', 64)])->json('codice'))->toBe('verifica_non_riuscita')
        ->and(alFinto('POST', '/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => $verificatore])->json('codice'))->toBe('verifica_non_riuscita');

    // Lo spegnimento dell'app dopo il codice lo rende inutile.
    $codice = alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone)->json('data.codice');
    alFinto('PATCH', '/v1/app/pm', ['stato' => 'disponibile'], $gettone);

    expect(alFinto('POST', '/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => $verificatore])->json('codice'))->toBe('verifica_non_riuscita')
        ->and(alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone)->json('codice'))->toBe('app_non_attiva');

    // Attiva, ma senza un indirizzo di ritorno: l'app non riceve ingressi.
    alFinto('PATCH', '/v1/app/pm', ['stato' => 'attivo'], $gettone);
    $finto->ritorno('pm', '');

    expect(alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone)->json('codice'))->toBe('servizio_non_disponibile');
});

it("lo scambio di una persona che non è più nel workspace è la stessa verifica_non_riuscita (T1.5)", function () {
    [$finto, $studio] = fintoConPm();
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $finto->membro($studio, $bruno, 'membro');
    $anna = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('bruno@example.com')['gettone']['gettone'])->json('data.gettone');
    $verificatore = str_repeat('v', 64);
    $codice = alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone)->json('data.codice');

    expect(alFinto('DELETE', "/v1/workspace/membri/{$bruno['id']}", gettone: $anna)->status())->toBe(204)
        ->and(alFinto('POST', '/v1/ingressi/scambio', ['codice' => $codice, 'verificatore' => $verificatore])->json('codice'))->toBe('verifica_non_riuscita');
});

it('un codice dell\'ingresso scade dopo 60 secondi (T1.5)', function () {
    [, $studio] = fintoConPm();
    $gettone = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    $verificatore = str_repeat('v', 64);
    $ingresso = alFinto('POST', '/v1/ingressi', ['app' => 'pm', 'sfida' => sfidaDi($verificatore)], $gettone);

    expect(now()->diffInSeconds($ingresso->json('data.scade_il'), true))->toBeBetween(59, 61);

    $this->travel(61)->seconds();

    expect(alFinto('POST', '/v1/ingressi/scambio', ['codice' => $ingresso->json('data.codice'), 'verificatore' => $verificatore])->json('codice'))->toBe('verifica_non_riuscita');
});
