<?php

use Illuminate\Support\Facades\Http;
use Zeiras\Auth\Api;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Testing\BackofficeFinto;

// #1590 (V1-V3, V5) e #1592 (v0.12.4): le aziende nel finto, il 403 di io.workspace.crea per un membro semplice e Turnstile con
// un invito. Qui ciò che un frontend vede dal suo lato; che le risposte e gli errori siano quelli del backoffice, byte per byte,
// lo prova il FintoTest del backoffice (G12).

/**
 * Anna proprietaria di «Studio», con un secondo workspace nella stessa azienda in cui Bruno è amministratore e Carla membro, e
 * un'altra azienda di Dora. [finto, studio, secondo, altro, gettone dell'accesso di una persona].
 *
 * @return array{BackofficeFinto, array<string, mixed>, array<string, mixed>, array<string, mixed>, Closure}
 */
function conLeAziende(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $bruno = $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $carla = $finto->persona('carla@example.com', PASSWORD, nome: 'Carla');
    $dora = $finto->persona('dora@example.com', PASSWORD, nome: 'Dora');
    $studio = $finto->workspace('Studio', $anna);
    $secondo = $finto->workspace('Altro studio', $anna, $studio['azienda_id']);
    $finto->membro($studio, $bruno, 'membro');
    $finto->membro($secondo, $bruno, 'amministratore');
    $finto->membro($studio, $carla, 'membro');
    $finto->membro($secondo, $carla, 'membro');
    // Un terzo workspace della stessa azienda dove Bruno e Carla non sono membri: nelle loro liste non compare.
    $finto->workspace('Terzo', $anna, $studio['azienda_id']);
    $altro = $finto->workspace('Studio di Dora', $dora);
    $accesso = fn (string $email) => entraNelFinto($email)['gettone']['gettone'];

    return [$finto, $studio, $secondo, $altro, $accesso];
}

it('aziende.mostra dà id, nome e il ruolo più alto della persona nei workspace dell\'azienda, non il primo trovato (T1.1)', function () {
    [, $studio, , , $accesso] = conLeAziende();

    $bruno = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}", gettone: $accesso('bruno@example.com'));
    $carla = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}", gettone: $accesso('carla@example.com'));
    $anna = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}", gettone: $accesso('anna@example.com'));

    // Bruno è membro nel primo workspace e amministratore nel secondo: l'azienda lo vede amministratore.
    expect($bruno->status())->toBe(200)
        ->and($bruno->json('data'))->toBe(['id' => $studio['azienda_id'], 'nome' => 'Studio', 'ruolo' => 'amministratore'])
        ->and($carla->json('data.ruolo'))->toBe('membro')
        ->and($anna->json('data.ruolo'))->toBe('proprietario');
});

it('aziende.mostra: un\'azienda non sua o inesistente è lo stesso 404 non_trovato, anche col gettone di un workspace (T1.1)', function () {
    [, $studio, , $altro, $accesso] = conLeAziende();
    $bruno = $accesso('bruno@example.com');
    $delWorkspace = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $bruno)->json('data.gettone');

    $altrui = alFinto('GET', "/v1/aziende/{$altro['azienda_id']}", gettone: $bruno);
    $inventata = alFinto('GET', '/v1/aziende/01k6r3a7c2e6g0j4m8p2s6v0x9', gettone: $bruno);

    expect($altrui->status())->toBe(404)
        ->and($altrui->json('codice'))->toBe('non_trovato')
        ->and($altrui->json())->toBe($inventata->json())
        ->and(alFinto('GET', "/v1/aziende/{$studio['azienda_id']}", gettone: $delWorkspace)->status())->toBe(200)
        ->and(alFinto('GET', "/v1/aziende/{$studio['azienda_id']}")->json('codice'))->toBe('gettone_assente');
});

it('aziende.workspace.elenca dà solo i workspace dell\'azienda di cui la persona è membro, col ruolo che ha lì, per nome e id, a pagine (T1.2)', function () {
    [, $studio, $secondo, , $accesso] = conLeAziende();
    $bruno = $accesso('bruno@example.com');

    $intero = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}/workspace", gettone: $bruno);
    $prima = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}/workspace?limite=1", gettone: $bruno);
    $dopo = alFinto('GET', "/v1/aziende/{$studio['azienda_id']}/workspace?limite=1&cursore=".urlencode($prima->json('successivo')), gettone: $bruno);

    expect($intero->json('data'))->toBe([[...$secondo, 'ruolo' => 'amministratore'], [...$studio, 'ruolo' => 'membro']])
        ->and($intero->json('successivo'))->toBeNull()
        ->and($prima->json('data'))->toBe([[...$secondo, 'ruolo' => 'amministratore']])
        ->and($prima->json('successivo'))->not->toBeNull()
        ->and($dopo->json('data'))->toBe([[...$studio, 'ruolo' => 'membro']])
        ->and($dopo->json('successivo'))->toBeNull();

});

it('aziende.workspace.elenca: un\'azienda altrui è 404, e un limite sbagliato su un\'azienda altrui è 422, prima del 404 (T1.2)', function () {
    [, , , $altro, $accesso] = conLeAziende();
    $bruno = $accesso('bruno@example.com');

    expect(alFinto('GET', "/v1/aziende/{$altro['azienda_id']}/workspace", gettone: $bruno)->status())->toBe(404)
        ->and(alFinto('GET', "/v1/aziende/{$altro['azienda_id']}/workspace?limite=0", gettone: $bruno)->status())->toBe(422);
});

it('il finto fa nascere un secondo workspace nella stessa azienda: workspace() con l\'azienda_id, e io.workspace.crea con azienda_id (T1.3)', function () {
    [$finto, $studio, , , $accesso] = conLeAziende();

    $nuovo = alFinto('POST', '/v1/io/workspace', ['nome' => 'Terzo', 'azienda_id' => $studio['azienda_id']], $accesso('anna@example.com'));

    expect($nuovo->status())->toBe(201)
        ->and($nuovo->json('data.azienda_id'))->toBe($studio['azienda_id'])
        ->and(fn () => $finto->workspace('Fuori', $finto->persona('gina@example.com', PASSWORD), '01k6r3a7c2e6g0j4m8p2s6v0x9'))
        ->toThrow(InvalidArgumentException::class);
});

it('io.workspace.crea con azienda_id: chi è solo membro prende 403 permesso_negato e non nasce niente; l\'amministratrice crea anche se altrove è membro (T2.1, T2.2)', function () {
    [, $studio, , $altro, $accesso] = conLeAziende();
    $conta = fn (string $gettone) => count(alFinto('GET', '/v1/io/workspace?limite=100', gettone: $gettone)->json('data'));
    $carla = $accesso('carla@example.com');
    $prima = $conta($carla);

    $negato = alFinto('POST', '/v1/io/workspace', ['nome' => 'Mio', 'azienda_id' => $studio['azienda_id']], $carla);
    $inesistente = alFinto('POST', '/v1/io/workspace', ['nome' => 'Mio', 'azienda_id' => '01k6r3a7c2e6g0j4m8p2s6v0x9'], $carla);
    $altrui = alFinto('POST', '/v1/io/workspace', ['nome' => 'Mio', 'azienda_id' => $altro['azienda_id']], $carla);

    expect($negato->status())->toBe(403)
        ->and($negato->json('codice'))->toBe('permesso_negato')
        ->and($conta($carla))->toBe($prima)
        ->and($inesistente->status())->toBe(404)
        ->and($altrui->json())->toBe($inesistente->json());

    $bruno = alFinto('POST', '/v1/io/workspace', ['nome' => 'Di Bruno', 'azienda_id' => $studio['azienda_id']], $accesso('bruno@example.com'));
    expect($bruno->status())->toBe(201)
        ->and($bruno->json('data.azienda_id'))->toBe($studio['azienda_id'])
        ->and($bruno->json('data.ruolo'))->toBe('proprietario');
});

it('io.workspace.crea: i rifiuti 403 e 404 non contano nel freno dei workspace nuovi (T2.1)', function () {
    [, $studio, , , $accesso] = conLeAziende();
    $carla = $accesso('carla@example.com');

    foreach (range(1, 12) as $n) {
        expect(alFinto('POST', '/v1/io/workspace', ['nome' => "Mio {$n}", 'azienda_id' => $studio['azienda_id']], $carla)->status())->toBe(403);
    }

    expect(alFinto('POST', '/v1/io/workspace', ['nome' => 'Mio'], $carla)->status())->toBe(201);
});

it('utenti.crea con un invito: un turnstile mandato si controlla, prima del codice dell\'invito (T1.5)', function () {
    [$finto, $studio, , , $accesso] = conLeAziende();
    $finto->consenti('@example.com')->accendiTurnstile();
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'nuova@example.com', 'ruolo' => 'membro'], alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso('anna@example.com'))->json('data.gettone'));
    $codice = $finto->ultimoInvito('nuova@example.com');
    $corpo = fn (array $altri) => ['email' => 'nuova@example.com', 'password' => PASSWORD, 'invito' => $codice, ...$altri];

    // Senza obbligo, l'invito vale senza widget; con un turnstile sbagliato, è 422 turnstile_non_valido prima dell'invito.
    expect(alFinto('POST', '/v1/utenti', $corpo(['turnstile' => 'altro', 'invito' => 'un-codice-falso']))->json('codice'))->toBe('turnstile_non_valido')
        ->and(alFinto('POST', '/v1/utenti', $corpo(['invito' => 'un-codice-falso']))->json('codice'))->toBe('verifica_non_riuscita')
        ->and(alFinto('POST', '/v1/utenti', $corpo(['turnstile' => BackofficeFinto::TURNSTILE_VALIDO]))->status())->toBe(202);
});

it('utenti.crea con un invito e l\'obbligo di Turnstile acceso: senza turnstile è 422 turnstile_non_valido, uguale per un invito vero e uno falso (T1.5)', function () {
    [$finto, $studio, , , $accesso] = conLeAziende();
    $finto->consenti('@example.com')->accendiTurnstile()->accendiTurnstileSullInvito();
    alFinto('POST', '/v1/workspace/inviti', ['email' => 'nuova@example.com', 'ruolo' => 'membro'], alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], $accesso('anna@example.com'))->json('data.gettone'));
    $vero = $finto->ultimoInvito('nuova@example.com');
    $corpo = fn (string $invito, array $altri = []) => ['email' => 'nuova@example.com', 'password' => PASSWORD, 'invito' => $invito, ...$altri];

    $senzaVero = alFinto('POST', '/v1/utenti', $corpo($vero));
    $senzaFalso = alFinto('POST', '/v1/utenti', $corpo('un-codice-falso'));

    expect($senzaVero->status())->toBe(422)
        ->and($senzaVero->json('codice'))->toBe('turnstile_non_valido')
        ->and($senzaFalso->json())->toBe($senzaVero->json())
        ->and(alFinto('POST', '/v1/utenti', $corpo('un-codice-falso', ['turnstile' => BackofficeFinto::TURNSTILE_VALIDO]))->json('codice'))->toBe('verifica_non_riuscita')
        ->and(alFinto('POST', '/v1/utenti', $corpo($vero, ['turnstile' => BackofficeFinto::TURNSTILE_VALIDO]))->status())->toBe(202);
});

it('ErroreApi tiene nelle estensioni anche gli elenchi del problema: schede di attese_aperte (#1556, T3.1)', function () {
    Http::fake(['*' => problema(409, 'attese_aperte', ['schede' => [['id' => '01J0000000000000000000K3AB', 'numero' => 3]], 'limite' => 5])]);

    expect(fn () => Api::senzaGettone()->post('/v1/board/schede/x/completamento', []))
        ->toThrow(fn (ErroreApi $e) => expect($e->estensioni)->toBe(['schede' => [['id' => '01J0000000000000000000K3AB', 'numero' => 3]], 'limite' => 5]));
});

it('ErroreApi tiene liste_consentite del 409 passaggio_non_consentito (#1556, T3.1)', function () {
    Http::fake(['*' => problema(409, 'passaggio_non_consentito', ['liste_consentite' => ['01J0000000000000000000L1AB', '01J0000000000000000000L2AB']])]);

    expect(fn () => Api::senzaGettone()->post('/v1/board/schede/x/spostamento', []))
        ->toThrow(fn (ErroreApi $e) => expect($e->estensioni['liste_consentite'])->toBe(['01J0000000000000000000L1AB', '01J0000000000000000000L2AB']));
});

it('un\'estensione non sostituisce un campo riservato del problema: codice, detail, title, status, type, instance restano al loro posto (#1556, T3.2)', function () {
    Http::fake(['*' => problema(409, 'limite_raggiunto', ['instance' => '/v1/board/schede/x', 'errors' => [['detail' => 'x', 'pointer' => '#/a']], 'limite' => 10])]);

    expect(fn () => Api::senzaGettone()->post('/v1/board/schede/x/collegamenti', []))
        ->toThrow(fn (ErroreApi $e) => expect($e->estensioni)->toBe(['limite' => 10])
            ->and($e->codice)->toBe('limite_raggiunto')
            ->and($e->stato)->toBe(409)
            ->and($e->dettaglio)->toBe('Dettaglio per la persona.')
            ->and($e->titolo)->toBe('Titolo'));
});
