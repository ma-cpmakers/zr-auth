<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #1698: il finto dei documenti legali. Spento com'è in produzione oggi (elenco vuoto, nessun documento in vigore); acceso con
// `documentiLegali()`. Che le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice (G67).

const DOCUMENTI_DI_PROVA = [
    'informativa-privacy' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => 'c017cdba0f35ad579ff3b001cf1600f63d9731bc51c610f52b38e47e78abd57a'],
    'termini-d-uso' => ['versione' => 'bozza-0.1', 'in_vigore_dal' => '2026-10-11', 'impronta' => '73ca77acbe0a4b7264e287bbbee56238ba1145b9bf4c5cc7ecd063e9969f08fd'],
];

it('legale.*: spento, l\'elenco è vuoto e ogni accettazione è 422; senza gettone è 401 (#1698, T5.4)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(alFinto('GET', '/v1/legale/documenti', gettone: $accesso)->json())->toBe(['data' => []])
        ->and(esito(alFinto('POST', '/v1/legale/accettazioni', ['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1'], $accesso)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('GET', '/v1/legale/documenti')))->toBe([401, 'gettone_assente']);
});

it('legale.*: acceso, accetta per la persona (201, poi 200 con la stessa riga) e dice accettato solo per lei (#1698, T5.2, T5.3)', function () {
    $finto = BackofficeFinto::attiva()->documentiLegali(DOCUMENTI_DI_PROVA);
    $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $anna = entraNelFinto('anna@example.com')['gettone']['gettone'];
    $bruno = entraNelFinto('bruno@example.com')['gettone']['gettone'];
    $corpo = ['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1'];

    $prima = alFinto('POST', '/v1/legale/accettazioni', $corpo, $anna);
    $seconda = alFinto('POST', '/v1/legale/accettazioni', $corpo, $anna);

    expect($prima->status())->toBe(201)
        ->and(array_keys($prima->json('data')))->toBe(['documento', 'versione', 'accettata_il'])
        ->and($seconda->status())->toBe(200)
        ->and($seconda->json('data'))->toBe($prima->json('data'))
        ->and(array_column(alFinto('GET', '/v1/legale/documenti', gettone: $anna)->json('data'), 'accettato'))->toBe([false, true])
        ->and(array_column(alFinto('GET', '/v1/legale/documenti', gettone: $bruno)->json('data'), 'accettato'))->toBe([false, false])
        ->and(array_keys(alFinto('GET', '/v1/legale/documenti', gettone: $anna)->json('data.0')))->toBe(['documento', 'versione', 'in_vigore_dal', 'impronta', 'accettato']);
});

it('legale.accettazioni.crea: un documento o una versione fuori vigore, un campo estraneo e un tipo sbagliato sono 422 (#1698, T5.3)', function (array $corpo) {
    $finto = BackofficeFinto::attiva()->documentiLegali(DOCUMENTI_DI_PROVA);
    $finto->persona('anna@example.com', PASSWORD);

    expect(esito(alFinto('POST', '/v1/legale/accettazioni', $corpo, entraNelFinto('anna@example.com')['gettone']['gettone'])))->toBe([422, 'dati_non_validi']);
})->with([
    'documento sconosciuto' => [['documento' => 'altro', 'versione' => 'bozza-0.1']],
    'versione non in vigore' => [['documento' => 'termini-d-uso', 'versione' => 'bozza-0.0']],
    'un array' => [['documento' => ['a'], 'versione' => 'bozza-0.1']],
    'un campo estraneo' => [['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1', 'utente_id' => 'x']],
]);

// #1699: l'accettazione obbligatoria. Acceso `documentiLegali()`, chi non ha accettato apre solo legale.*, io.mostra e l'uscita; ogni
// altro metodo col gettone è 403 accettazione_richiesta con i `mancanti`. Chi si registra accetta i documenti in vigore.

it('accettazione obbligatoria: chi non ha accettato apre solo legale.*, io.mostra e l\'uscita, e ogni altro metodo è 403 con i mancanti (#1699, T6.2)', function () {
    $finto = BackofficeFinto::attiva()->documentiLegali(DOCUMENTI_DI_PROVA);
    $finto->persona('anna@example.com', PASSWORD);
    $accesso = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $chiuso = alFinto('GET', '/v1/app', gettone: $accesso);

    expect(esito($chiuso))->toBe([403, 'accettazione_richiesta'])
        ->and($chiuso->json('mancanti'))->toBe([['documento' => 'informativa-privacy', 'versione' => 'bozza-0.1'], ['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1']])
        ->and(alFinto('GET', '/v1/io', gettone: $accesso)->status())->toBe(200)
        ->and(alFinto('GET', '/v1/legale/documenti', gettone: $accesso)->status())->toBe(200);

    alFinto('POST', '/v1/legale/accettazioni', ['documento' => 'informativa-privacy', 'versione' => 'bozza-0.1'], $accesso);

    expect(alFinto('GET', '/v1/app', gettone: $accesso)->json('mancanti'))->toBe([['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1']]);

    alFinto('POST', '/v1/legale/accettazioni', ['documento' => 'termini-d-uso', 'versione' => 'bozza-0.1'], $accesso);

    // Accettato tutto, il 403 di prima non c'è più: il gettone dell'accesso vede il suo (gettone_senza_workspace).
    expect(esito(alFinto('GET', '/v1/app', gettone: $accesso)))->toBe([403, 'gettone_senza_workspace']);
});

it('accettazione obbligatoria: chi si registra con utenti.crea ha già accettato i documenti in vigore; spenta, niente cambia (#1699, T6.1)', function () {
    $finto = BackofficeFinto::attiva()->consenti('@example.com')->documentiLegali(DOCUMENTI_DI_PROVA);
    expect(alFinto('POST', '/v1/utenti', ['email' => 'anna@example.com', 'password' => PASSWORD, 'termini_accettati' => true])->status())->toBe(202);
    alFinto('POST', '/v1/io/email/verifica', ['email' => 'anna@example.com', 'password' => PASSWORD, 'codice' => $finto->ultimoCodice('anna@example.com')]);
    $anna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    // Ha accettato alla registrazione: il suo gettone dell'accesso non incontra l'accettazione (ma il workspace che non ha).
    expect(esito(alFinto('GET', '/v1/app', gettone: $anna)))->toBe([403, 'gettone_senza_workspace'])
        ->and(array_column(alFinto('GET', '/v1/legale/documenti', gettone: $anna)->json('data'), 'accettato'))->toBe([true, true]);

    // Una persona creata a mano nel finto è come un account di prima: non ha accettato.
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');
    $bruno = entraNelFinto('bruno@example.com')['gettone']['gettone'];

    expect(esito(alFinto('GET', '/v1/app', gettone: $bruno)))->toBe([403, 'accettazione_richiesta']);
});

it('accettazione obbligatoria: spenta, nessun metodo cambia (#1699, T6.2)', function () {
    $finto = BackofficeFinto::attiva();
    $finto->persona('anna@example.com', PASSWORD);
    $anna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(esito(alFinto('GET', '/v1/app', gettone: $anna)))->toBe([403, 'gettone_senza_workspace']);
});
