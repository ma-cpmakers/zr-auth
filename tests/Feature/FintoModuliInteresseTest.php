<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #2869: il finto di moduli.interesse.* («Avvisami al lancio»). Che le risposte e gli errori siano quelli del backoffice lo prova il
// FintoTest del backoffice (G67).

/** Il gettone dell'accesso di una persona nata nel finto. */
function gettoneInteresse(BackofficeFinto $finto, string $email, string $nome = 'Anna'): string
{
    $finto->persona($email, PASSWORD, nome: $nome);

    return entraNelFinto($email)['gettone']['gettone'];
}

it('moduli.interesse: 201 la prima volta, 200 se lo ripete, la lista è della sola persona, dalla più recente, a pagine (#2869, C.1, C.2)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = gettoneInteresse($finto, 'anna@example.com');
    $bruno = gettoneInteresse($finto, 'bruno@example.com', 'Bruno');

    $prima = alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)->assertCreated();
    expect(array_keys($prima->json('data')))->toBe(['modulo', 'richiesto_il'])
        ->and(alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)->assertOk()->json('data'))->toBe($prima->json('data'));

    alFinto('POST', '/v1/moduli/due/interesse', gettone: $anna)->assertCreated();
    alFinto('POST', '/v1/moduli/tre/interesse', gettone: $anna)->assertCreated();
    alFinto('POST', '/v1/moduli/quattro/interesse', gettone: $bruno)->assertCreated();

    $primaPagina = alFinto('GET', '/v1/moduli/interesse?limite=2', gettone: $anna);
    $seconda = alFinto('GET', '/v1/moduli/interesse?limite=2&cursore='.urlencode($primaPagina->json('successivo')), gettone: $anna);

    expect(array_keys($primaPagina->json('data.0')))->toBe(['modulo', 'richiesto_il'])
        ->and(array_column($primaPagina->json('data'), 'modulo'))->toBe(['tre', 'due'])
        ->and(array_column($seconda->json('data'), 'modulo'))->toBe(['uno'])
        ->and($seconda->json('successivo'))->toBeNull()
        ->and(array_column(alFinto('GET', '/v1/moduli/interesse', gettone: $bruno)->json('data'), 'modulo'))->toBe(['quattro'])
        ->and(esito(alFinto('GET', '/v1/moduli/interesse?limite=0', gettone: $anna)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('GET', '/v1/moduli/interesse')))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('POST', '/v1/moduli/uno/interesse')))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('DELETE', '/v1/moduli/uno/interesse')))->toBe([401, 'gettone_assente']);
});

it('moduli.interesse.elimina: 204, poi 404; una richiesta tolta e rifatta torna in testa; non toglie quella di un\'altra persona (#2869, C.3)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = gettoneInteresse($finto, 'anna@example.com');
    $bruno = gettoneInteresse($finto, 'bruno@example.com', 'Bruno');

    alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)->assertCreated();
    alFinto('POST', '/v1/moduli/due/interesse', gettone: $anna)->assertCreated();

    expect(esito(alFinto('DELETE', '/v1/moduli/uno/interesse', gettone: $bruno)))->toBe([404, 'non_trovato']);
    alFinto('DELETE', '/v1/moduli/uno/interesse', gettone: $anna)->assertNoContent();
    expect(esito(alFinto('DELETE', '/v1/moduli/uno/interesse', gettone: $anna)))->toBe([404, 'non_trovato']);

    alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)->assertCreated();

    expect(array_column(alFinto('GET', '/v1/moduli/interesse', gettone: $anna)->json('data'), 'modulo'))->toBe(['uno', 'due']);
});

it('moduli.interesse: un codice fuori forma è 404 e non scrive niente (#2869, C.2)', function (string $modulo) {
    $finto = BackofficeFinto::attiva();
    $anna = gettoneInteresse($finto, 'anna@example.com');

    expect(esito(alFinto('POST', '/v1/moduli/'.$modulo.'/interesse', gettone: $anna)))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('DELETE', '/v1/moduli/'.$modulo.'/interesse', gettone: $anna)))->toBe([404, 'non_trovato'])
        ->and(alFinto('GET', '/v1/moduli/interesse', gettone: $anna)->json('data'))->toBe([]);
})->with(['Bookings', '-bookings', 'book_ings', 'a.b', str_repeat('a', 41), 'caf%C3%A9']);

it('moduli.interesse.crea: la cinquantunesima è 409 limite_raggiunto con limite 50, una già chiesta resta 200, e togliere una libera un posto (#2869, C.2)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = gettoneInteresse($finto, 'anna@example.com');

    foreach (range(1, 50) as $n) {
        alFinto('POST', '/v1/moduli/modulo-'.$n.'/interesse', gettone: $anna)->assertCreated();
    }

    $oltre = alFinto('POST', '/v1/moduli/modulo-51/interesse', gettone: $anna);

    expect(esito($oltre))->toBe([409, 'limite_raggiunto'])
        ->and($oltre->json('limite'))->toBe(50);
    alFinto('POST', '/v1/moduli/modulo-7/interesse', gettone: $anna)->assertOk();
    alFinto('DELETE', '/v1/moduli/modulo-7/interesse', gettone: $anna)->assertNoContent();
    alFinto('POST', '/v1/moduli/modulo-51/interesse', gettone: $anna)->assertCreated();
});

it('moduli.interesse: con i documenti legali in vigore chi non ha accettato è 403 accettazione_richiesta (#2869, C.4)', function () {
    $finto = BackofficeFinto::attiva()->documentiLegali(DOCUMENTI_DI_PROVA);
    $anna = gettoneInteresse($finto, 'anna@example.com');

    expect(esito(alFinto('GET', '/v1/moduli/interesse', gettone: $anna)))->toBe([403, 'accettazione_richiesta'])
        ->and(esito(alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)))->toBe([403, 'accettazione_richiesta']);
});

it('moduli.interesse.crea: 60 in un\'ora per persona, poi 429 con Retry-After; un\'altra persona passa (#2869, C.4)', function () {
    $finto = BackofficeFinto::attiva();
    $anna = gettoneInteresse($finto, 'anna@example.com');
    $bruno = gettoneInteresse($finto, 'bruno@example.com', 'Bruno');

    alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)->assertCreated();

    foreach (range(2, 60) as $volta) {
        alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna)->assertOk();
    }

    $oltre = alFinto('POST', '/v1/moduli/uno/interesse', gettone: $anna);

    expect(esito($oltre))->toBe([429, 'troppe_richieste'])
        ->and($oltre->headers->has('Retry-After'))->toBeTrue();
    alFinto('POST', '/v1/moduli/uno/interesse', gettone: $bruno)->assertCreated();
});
