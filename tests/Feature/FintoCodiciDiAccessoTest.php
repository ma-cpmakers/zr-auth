<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2478: il finto di accessi.codici.crea e accessi.codici.verifica.crea. Che le risposte e gli errori siano quelli del backoffice lo prova
// il FintoTest del backoffice (G67).

const OSPITI_DI_PROVA = [
    'condizioni-ospite' => ['versione' => 'bozza-2', 'in_vigore_dal' => '2026-10-24', 'impronta' => 'e6b5557e3416631cde2c2bf4fbc27ff71aa8f09e1b52fefa5de750a2cde06171'],
];

it('accessi.codici.crea: la stessa 202 per un\'email con un account e per una senza, e il codice si legge da ultimoCodice() (#2478, T1.1)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);

    $con = alFinto('POST', '/v1/accessi/codici', ['email' => 'anna@example.com']);
    $senza = alFinto('POST', '/v1/accessi/codici', ['email' => 'nessuno@example.com']);

    expect($con->status())->toBe(202)
        ->and($senza->status())->toBe(202)
        ->and(array_keys($senza->json('data')))->toBe(['email', 'scade_il', 'nuovo_codice_dal'])
        ->and($finto->ultimoCodice('nessuno@example.com'))->toMatch('/^\d{6}$/');
});

it('accessi.codici.verifica.crea: un\'email senza account nasce col codice giusto e i tre campi, e senza i campi il codice non si consuma (#2478, T2.3, T2.4)', function () {
    $finto = BackofficeFinto::attiva();
    alFinto('POST', '/v1/accessi/codici', ['email' => 'olga@example.com']);
    $codice = $finto->ultimoCodice('olga@example.com');

    foreach (range(1, 3) as $giro) {
        $senzaCampi = alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'olga@example.com', 'codice' => $codice]);
        expect(esito($senzaCampi))->toBe([422, 'dati_non_validi'])
            ->and(array_column($senzaCampi->json('errors'), 'pointer'))->toBe(['#/nome', '#/cognome', '#/termini_accettati']);
    }

    $entrata = alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'olga@example.com', 'codice' => $codice, 'nome' => 'Olga', 'cognome' => 'Ospite', 'termini_accettati' => true]);

    expect($entrata->status())->toBe(201)
        ->and($entrata->json('data.gettone.utente.cognome'))->toBe('Ospite')
        ->and($entrata->json('data.gettone.workspace'))->toBeNull()
        ->and(esito(alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'olga@example.com', 'codice' => $codice, 'nome' => 'Olga', 'cognome' => 'Ospite', 'termini_accettati' => true])))->toBe([422, 'dati_non_validi']);
});

it('accessi.codici.verifica.crea: un codice sbagliato è un solo errore su #/codice, uguale per un\'email con un account e per una senza (#2478, T2.1)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    alFinto('POST', '/v1/accessi/codici', ['email' => 'anna@example.com']);

    $con = alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'anna@example.com', 'codice' => '000000']);
    $senza = alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'nessuno@example.com', 'codice' => '000000']);

    expect($con->status())->toBe(422)
        ->and(array_column($con->json('errors'), 'pointer'))->toBe(['#/codice'])
        ->and(array_column($senza->json('errors'), 'pointer'))->toBe(['#/codice'])
        ->and($senza->json('errors'))->toBe($con->json('errors'));
});

it('accessi.codici.verifica.crea: per una persona che c\'è fa entrare e verifica l\'email; i documenti ospite li accetta solo chi nasce così (#2478, T2.5, T2.7)', function () {
    $finto = BackofficeFinto::attiva()->documentiLegali(['termini-d-uso' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => '73ca77acbe0a4b7264e287bbbee56238ba1145b9bf4c5cc7ecd063e9969f08fd']])->documentiOspite(OSPITI_DI_PROVA);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno', verificata: false);
    alFinto('POST', '/v1/accessi/codici', ['email' => 'bruno@example.com']);
    $bruno = alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'bruno@example.com', 'codice' => $finto->ultimoCodice('bruno@example.com')]);

    expect($bruno->status())->toBe(201)
        ->and($bruno->json('data.gettone.utente.email_verificata_il'))->not->toBeNull();
});

it('i documenti ospite: l\'ospite li vede e li accetta, con una versione nuova ogni metodo è 403, e chi accetta i documenti dei workspace non è più un ospite (#2478, T2.7)', function () {
    $finto = BackofficeFinto::attiva()
        ->documentiLegali(['termini-d-uso' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => '73ca77acbe0a4b7264e287bbbee56238ba1145b9bf4c5cc7ecd063e9969f08fd']])
        ->documentiOspite(OSPITI_DI_PROVA)
        ->consenti('@example.com');
    alFinto('POST', '/v1/accessi/codici', ['email' => 'olga@example.com']);
    $gettone = alFinto('POST', '/v1/accessi/codici/verifica', ['email' => 'olga@example.com', 'codice' => $finto->ultimoCodice('olga@example.com'), 'nome' => 'Olga', 'cognome' => 'Ospite', 'termini_accettati' => true])
        ->json('data.gettone.gettone');

    $elenco = alFinto('GET', '/v1/legale/documenti', gettone: $gettone)->json('data');
    expect(array_column($elenco, 'documento'))->toBe(['condizioni-ospite'])
        ->and($elenco[0]['accettato'])->toBeTrue()
        ->and(alFinto('GET', '/v1/accessi', gettone: $gettone)->status())->toBe(200)
        ->and(esito(alFinto('GET', '/v1/io/workspace', gettone: $gettone)))->toBe([403, 'accettazione_richiesta']);

    $finto->documentiOspite(['condizioni-ospite' => ['versione' => 'bozza-3'] + OSPITI_DI_PROVA['condizioni-ospite']]);
    $rifiutato = alFinto('GET', '/v1/accessi', gettone: $gettone);
    expect(esito($rifiutato))->toBe([403, 'accettazione_richiesta'])
        ->and($rifiutato->json('mancanti'))->toBe([['documento' => 'condizioni-ospite', 'versione' => 'bozza-3']]);

    expect(alFinto('POST', '/v1/legale/accettazioni', ['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1'], $gettone)->status())->toBe(201)
        ->and(array_column(alFinto('GET', '/v1/legale/documenti', gettone: $gettone)->json('data'), 'documento'))->toBe(['termini-d-uso'])
        ->and(alFinto('GET', '/v1/io/workspace', gettone: $gettone)->status())->toBe(200);
});

it('un ospite fuori dalla lista e senza invito non accetta i documenti dei workspace: 403 registrazione_non_aperta; con la lista, un invito vivo o la registrazione aperta passa (#2574)', function () {
    $finto = BackofficeFinto::attiva()
        ->documentiLegali(['termini-d-uso' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => '73ca77acbe0a4b7264e287bbbee56238ba1145b9bf4c5cc7ecd063e9969f08fd']])
        ->documentiOspite(OSPITI_DI_PROVA);
    $accetta = ['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1'];
    $ospite = function (string $email) use ($finto): string {
        alFinto('POST', '/v1/accessi/codici', ['email' => $email]);

        return alFinto('POST', '/v1/accessi/codici/verifica', ['email' => $email, 'codice' => $finto->ultimoCodice($email), 'nome' => 'Olga', 'cognome' => 'Ospite', 'termini_accettati' => true])
            ->json('data.gettone.gettone');
    };

    $fuori = $ospite('fuori@fuori-lista.it');
    $rifiuto = alFinto('POST', '/v1/legale/accettazioni', $accetta, $fuori);
    expect(esito($rifiuto))->toBe([403, 'registrazione_non_aperta'])
        ->and(array_column(alFinto('GET', '/v1/legale/documenti', gettone: $fuori)->json('data'), 'documento'))->toBe(['condizioni-ospite'])
        ->and(esito(alFinto('GET', '/v1/io/workspace', gettone: $fuori)))->toBe([403, 'accettazione_richiesta']);

    // Un invito vivo per quell'email, di un workspace qualsiasi.
    $anna = $finto->persona('anna@example.com', PASSWORD, nome: 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $gettoneDiAnna = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto('anna@example.com')['gettone']['gettone'])->json('data.gettone');
    expect(alFinto('POST', '/v1/workspace/inviti', ['email' => 'fuori@fuori-lista.it', 'ruolo' => 'membro'], $gettoneDiAnna)->status())->toBe(201)
        ->and(alFinto('POST', '/v1/legale/accettazioni', $accetta, $fuori)->status())->toBe(201);

    // Un'altra email: la lista per indirizzo, poi per dominio.
    $finto->consenti('lista@fuori-lista.it');
    expect(alFinto('POST', '/v1/legale/accettazioni', $accetta, $ospite('lista@fuori-lista.it'))->status())->toBe(201);

    $finto->consenti('@dominio-consentito.it');
    expect(alFinto('POST', '/v1/legale/accettazioni', $accetta, $ospite('x@dominio-consentito.it'))->status())->toBe(201);
});
