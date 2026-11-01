<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2868: il finto di io.provider.* (collegare e scollegare un provider a un account già aperto). Che le risposte e gli errori siano quelli
// del backoffice lo prova il FintoTest del backoffice (G67).

/** Il finto con i tre provider accesi, due persone e il gettone dell'accesso di ognuna: [finto, gettone di Anna, gettone di Bruno]. */
function fintoConDuePersone(): array
{
    $finto = BackofficeFinto::attiva()->provider('google', 'linkedin-openid', 'facebook');
    $finto->persona('anna@example.com', PASSWORD);
    $finto->persona('bruno@example.com', PASSWORD, nome: 'Bruno');

    return [$finto, entraNelFinto('anna@example.com')['gettone']['gettone'], entraNelFinto('bruno@example.com')['gettone']['gettone']];
}

/** Collega un provider: parte, dice cosa risponde il provider e riporta codice e stato. */
function collegaNelFinto(BackofficeFinto $finto, string $gettone, string $provider, string $email = 'anna@example.com', ?string $id = null)
{
    $stato = alFinto('POST', "/v1/io/provider/{$provider}/autorizzazioni", gettone: $gettone)->json('data.stato');
    $finto->identitaDelProvider($provider, 'codice-'.$provider, $email, id: $id);

    return alFinto('POST', "/v1/io/provider/{$provider}", ['codice' => 'codice-'.$provider, 'stato' => $stato], $gettone);
}

it('io.provider: collega (201), lo ripete (200), elenca dal più recente a pagine solo i suoi, senza email né id del provider (#2868, B.1, B.3)', function () {
    [$finto, $anna, $bruno] = fintoConDuePersone();

    expect(alFinto('GET', '/v1/io/provider', gettone: $anna)->json())->toBe(['data' => [], 'successivo' => null]);

    $prima = collegaNelFinto($finto, $anna, 'google');
    $ancora = collegaNelFinto($finto, $anna, 'google');
    collegaNelFinto($finto, $anna, 'linkedin-openid');
    collegaNelFinto($finto, $bruno, 'facebook', 'bruno@example.com');

    expect([$prima->status(), $ancora->status()])->toBe([201, 200])
        ->and(array_keys($prima->json('data')))->toBe(['provider', 'collegato_il'])
        ->and($ancora->json('data'))->toBe($prima->json('data'));

    $primaPagina = alFinto('GET', '/v1/io/provider?limite=1', gettone: $anna);
    $seconda = alFinto('GET', '/v1/io/provider?limite=1&cursore='.urlencode($primaPagina->json('successivo')), gettone: $anna);

    expect(array_column($primaPagina->json('data'), 'provider'))->toBe(['linkedin-openid'])
        ->and(array_column($seconda->json('data'), 'provider'))->toBe(['google'])
        ->and($seconda->json('successivo'))->toBeNull()
        ->and(array_column(alFinto('GET', '/v1/io/provider', gettone: $bruno)->json('data'), 'provider'))->toBe(['facebook'])
        ->and(alFinto('GET', '/v1/io/provider', gettone: $anna)->body())->not->toContain('anna@example.com')->not->toContain('finto-')
        ->and(esito(alFinto('GET', '/v1/io/provider?limite=0', gettone: $anna)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('GET', '/v1/io/provider')))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('POST', '/v1/io/provider/google/autorizzazioni')))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('DELETE', '/v1/io/provider/google')))->toBe([401, 'gettone_assente']);
});

it('io.provider.crea: un\'identità di un\'altra persona è 409 identita_gia_collegata, un altro account dello stesso provider 409 provider_gia_collegato (#2868, B.3)', function () {
    [$finto, $anna, $bruno] = fintoConDuePersone();
    collegaNelFinto($finto, $anna, 'google', 'anna@example.com', 'id-di-anna');

    expect(esito(collegaNelFinto($finto, $bruno, 'google', 'bruno@example.com', 'id-di-anna')))->toBe([409, 'identita_gia_collegata'])
        ->and(esito(collegaNelFinto($finto, $anna, 'google', 'anna@example.com', 'un-altro-account')))->toBe([409, 'provider_gia_collegato'])
        ->and(alFinto('GET', '/v1/io/provider', gettone: $bruno)->json('data'))->toBe([]);
});

it('io.provider: uno stato dell\'accesso, di un\'altra persona o già usato è 422 verifica_non_riuscita, e lo stato di un collegamento non fa entrare nessuno (#2868, B.3)', function () {
    [$finto, $anna, $bruno] = fintoConDuePersone();
    $finto->identitaDelProvider('google', 'codice-google', 'anna@example.com');

    $dellAccesso = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
    $diBruno = alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $bruno)->json('data.stato');
    $diAnna = alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $anna)->json('data.stato');

    expect(esito(alFinto('POST', '/v1/io/provider/google', ['codice' => 'codice-google', 'stato' => $dellAccesso], $anna)))->toBe([422, 'verifica_non_riuscita'])
        ->and(esito(alFinto('POST', '/v1/io/provider/google', ['codice' => 'codice-google', 'stato' => $diBruno], $anna)))->toBe([422, 'verifica_non_riuscita'])
        ->and(esito(alFinto('POST', '/v1/io/provider/google', ['codice' => 'codice-google', 'stato' => $diBruno], $bruno)))->toBe([422, 'verifica_non_riuscita'])
        ->and(esito(alFinto('POST', '/v1/accessi/provider/google', ['codice' => 'codice-google', 'stato' => $diAnna])))->toBe([422, 'verifica_non_riuscita'])
        ->and(alFinto('GET', '/v1/io/provider', gettone: $anna)->json('data'))->toBe([]);
});

it('io.provider.autorizzazioni.crea: senza corpo, un provider spento o sconosciuto è 404; il corpo vuole codice e stato (#2868, B.2)', function () {
    $finto = BackofficeFinto::attiva()->provider('google');
    $finto->persona('anna@example.com', PASSWORD);
    $anna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    $partenza = alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $anna);

    expect($partenza->status())->toBe(201)
        ->and(array_keys($partenza->json('data')))->toBe(['url', 'stato', 'scade_il'])
        ->and(esito(alFinto('POST', '/v1/io/provider/facebook/autorizzazioni', gettone: $anna)))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('POST', '/v1/io/provider/google/autorizzazioni', ['ritorno' => 'bookings'], $anna)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('POST', '/v1/io/provider/facebook', ['codice' => 'x', 'stato' => str_repeat('a', 43)], $anna)))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('POST', '/v1/io/provider/google', [], $anna)))->toBe([422, 'dati_non_validi']);
});

it('io.provider.elimina: 204, poi 404; non toglie quello di un\'altra persona; lo scollegato non fa più entrare e si può ricollegare (#2868, B.4)', function () {
    [$finto, $anna, $bruno] = fintoConDuePersone();
    collegaNelFinto($finto, $anna, 'google');

    expect(esito(alFinto('DELETE', '/v1/io/provider/google', gettone: $bruno)))->toBe([404, 'non_trovato'])
        ->and(alFinto('DELETE', '/v1/io/provider/google', gettone: $anna)->status())->toBe(204)
        ->and(esito(alFinto('DELETE', '/v1/io/provider/google', gettone: $anna)))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('DELETE', '/v1/io/provider/nonesiste', gettone: $anna)))->toBe([404, 'non_trovato'])
        ->and(alFinto('GET', '/v1/io/provider', gettone: $anna)->json('data'))->toBe([]);

    // Scollegata, l'identità non fa entrare: la stessa 422 di tutti i rifiuti.
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
    expect(esito(alFinto('POST', '/v1/accessi/provider/google', ['codice' => 'codice-google', 'stato' => $stato])))->toBe([422, 'verifica_non_riuscita']);

    // Ricollegata, sì.
    expect(collegaNelFinto($finto, $anna, 'google')->status())->toBe(201);
    $stato = alFinto('POST', '/v1/accessi/provider/google/autorizzazioni')->json('data.stato');
    expect(alFinto('POST', '/v1/accessi/provider/google', ['codice' => 'codice-google', 'stato' => $stato])->status())->toBe(201);
});

it('io.provider: con i documenti legali in vigore chi non ha accettato è 403 accettazione_richiesta; i freni sono della persona (#2868, B.5)', function () {
    $finto = BackofficeFinto::attiva()->provider('google')->documentiLegali(DOCUMENTI_DI_PROVA);
    $finto->persona('anna@example.com', PASSWORD);
    $anna = entraNelFinto('anna@example.com')['gettone']['gettone'];

    expect(esito(alFinto('GET', '/v1/io/provider', gettone: $anna)))->toBe([403, 'accettazione_richiesta'])
        ->and(esito(alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $anna)))->toBe([403, 'accettazione_richiesta'])
        ->and(esito(alFinto('DELETE', '/v1/io/provider/google', gettone: $anna)))->toBe([403, 'accettazione_richiesta']);
});

it('io.provider.autorizzazioni.crea: 20 in un\'ora per persona, poi 429 con Retry-After; un\'altra persona passa (#2868, B.5)', function () {
    [, $anna, $bruno] = fintoConDuePersone();

    foreach (range(1, 20) as $volta) {
        expect(alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $anna)->status())->toBe(201);
    }

    $oltre = alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $anna);

    expect(esito($oltre))->toBe([429, 'troppe_richieste'])
        ->and($oltre->header('Retry-After'))->toMatch('/^\d+$/')
        ->and(alFinto('POST', '/v1/io/provider/google/autorizzazioni', gettone: $bruno)->status())->toBe(201);
});
