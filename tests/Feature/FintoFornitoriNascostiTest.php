<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// #3321: il finto di io.fornitori.nascosti.* (le aziende che la persona nasconde dalla cornice di cliente). Che le risposte e gli errori siano quelli del
// backoffice lo prova il FintoTest del backoffice (G67).

/** Il finto con Cora, cliente di due studi, e il suo gettone dell'accesso. */
function fintoDiCora(): array
{
    $finto = BackofficeFinto::attiva();
    $cora = $finto->persona('cora@example.com', PASSWORD, nome: 'Cora');
    $titolare = $finto->persona('verdi@example.com', PASSWORD, nome: 'Verdi');
    $verdi = $finto->workspace('Studio Verdi', $titolare);
    $rossi = $finto->workspace('Studio Rossi', $titolare);
    $finto->cliente($verdi, $cora)->cliente($rossi, $cora);

    return [$finto, entraNelFinto('cora@example.com')['gettone']['gettone'], $verdi, $rossi, $cora];
}

it('io.fornitori.nascosti: 201 la prima volta con id, nome e colore, 200 se lo ripete; la lista è dei soli nascosti, per nome, a pagine (#3321, N1)', function () {
    [, $cora, $verdi, $rossi] = fintoDiCora();

    $prima = alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id']], $cora)->assertCreated();
    expect($prima->json('data'))->toBe(['id' => $verdi['id'], 'nome' => 'Studio Verdi', 'colore' => 'neutral'])
        ->and(alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id']], $cora)->assertOk()->json('data'))->toBe($prima->json('data'));
    alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $rossi['id']], $cora)->assertCreated();

    $primaPagina = alFinto('GET', '/v1/io/fornitori/nascosti?limite=1', gettone: $cora)->assertOk();
    $seconda = alFinto('GET', '/v1/io/fornitori/nascosti?limite=1&cursore='.urlencode($primaPagina->json('successivo')), gettone: $cora)->assertOk();

    expect(array_column($primaPagina->json('data'), 'nome'))->toBe(['Studio Rossi'])
        ->and(array_column($seconda->json('data'), 'nome'))->toBe(['Studio Verdi'])
        ->and($seconda->json('successivo'))->toBeNull();
});

it('io.fornitori.nascosti.elimina: 204 anche se non era nascosta, e la lista la perde; non toglie quella di un\'altra persona (#3321, N1, N2)', function () {
    [$finto, $cora, $verdi] = fintoDiCora();
    $dario = $finto->persona('dario@example.com', PASSWORD, nome: 'Dario');
    $finto->cliente($verdi, $dario);
    $gettoneDario = entraNelFinto('dario@example.com')['gettone']['gettone'];
    alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id']], $cora)->assertCreated();

    alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$verdi['id'], gettone: $gettoneDario)->assertNoContent();
    expect(alFinto('GET', '/v1/io/fornitori/nascosti', gettone: $cora)->json('data'))->toHaveCount(1);

    alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$verdi['id'], gettone: $cora)->assertNoContent();
    alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$verdi['id'], gettone: $cora)->assertNoContent();
    expect(alFinto('GET', '/v1/io/fornitori/nascosti', gettone: $cora)->json('data'))->toBe([]);
});

it('io.fornitori.nascosti: senza una relazione da cliente è 404 non_trovato; il corpo è validato; senza gettone è 401 (#3321, N1)', function () {
    [$finto, $cora, $verdi] = fintoDiCora();
    $titolare = $finto->persona('altri@example.com', PASSWORD, nome: 'Altri');
    $estraneo = $finto->workspace('Studio Estraneo', $titolare);

    foreach ([$estraneo['id'], strtolower((string) Illuminate\Support\Str::ulid())] as $id) {
        expect(esito(alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $id], $cora)))->toBe([404, 'non_trovato'])
            ->and(esito(alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$id, gettone: $cora)))->toBe([404, 'non_trovato']);
    }

    expect(esito(alFinto('DELETE', '/v1/io/fornitori/nascosti/non-un-id', gettone: $cora)))->toBe([404, 'non_trovato'])
        ->and(esito(alFinto('POST', '/v1/io/fornitori/nascosti', [], $cora)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => 'x'], $cora)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id'], 'altro' => 1], $cora)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('GET', '/v1/io/fornitori/nascosti')))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id']])))->toBe([401, 'gettone_assente'])
        ->and(esito(alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$verdi['id'])))->toBe([401, 'gettone_assente']);
});

it('io.fornitori.nascosti: lo spazio spento, l\'email non verificata e il workspace in eliminazione tolgono la relazione (#3321, N1)', function () {
    $finto = BackofficeFinto::attiva();
    $titolare = $finto->persona('verdi@example.com', PASSWORD, nome: 'Verdi');
    $studio = $finto->workspace('Studio Verdi', $titolare);
    $spenta = $finto->persona('spenta@example.com', PASSWORD, nome: 'Spenta');
    $nonVerificata = $finto->persona('nonverificata@example.com', PASSWORD, nome: 'Non', verificata: false);
    $finto->cliente($studio, $spenta, false)->cliente($studio, $nonVerificata);
    $gettoneSpenta = entraNelFinto('spenta@example.com')['gettone']['gettone'];

    expect(esito(alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $studio['id']], $gettoneSpenta)))->toBe([404, 'non_trovato']);
});

it('io.fornitori.nascosti: 120 scritture in un\'ora per persona, poi 429 con Retry-After; un\'altra persona passa (#3321, N3)', function () {
    [$finto, $cora, $verdi] = fintoDiCora();
    $dario = $finto->persona('dario@example.com', PASSWORD, nome: 'Dario');
    $finto->cliente($verdi, $dario);
    $gettoneDario = entraNelFinto('dario@example.com')['gettone']['gettone'];

    foreach (range(1, 120) as $volta) {
        alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$verdi['id'], gettone: $cora)->assertNoContent();
    }

    $oltre = alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id']], $cora);

    expect(esito($oltre))->toBe([429, 'troppe_richieste'])
        ->and($oltre->hasHeader('Retry-After'))->toBeTrue();
    alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $verdi['id']], $gettoneDario)->assertCreated();
});

it('io.fornitori.elenca: le aziende di cui la persona è cliente meno i nascosti, per nome, a pagine; la relazione di un\'altra persona non si vede (#3320, F1, F3)', function () {
    [$finto, $cora, $verdi, $rossi] = fintoDiCora();
    $dario = $finto->persona('dario@example.com', PASSWORD, nome: 'Dario');
    $gettoneDario = entraNelFinto('dario@example.com')['gettone']['gettone'];

    $tutte = alFinto('GET', '/v1/io/fornitori', gettone: $cora)->assertOk();
    expect($tutte->json('data'))->toBe([
        ['id' => $rossi['id'], 'nome' => 'Studio Rossi', 'colore' => 'neutral'],
        ['id' => $verdi['id'], 'nome' => 'Studio Verdi', 'colore' => 'neutral'],
    ])->and($tutte->json('successivo'))->toBeNull()
        ->and(alFinto('GET', '/v1/io/fornitori', gettone: $gettoneDario)->json('data'))->toBe([]);

    $prima = alFinto('GET', '/v1/io/fornitori?limite=1', gettone: $cora)->assertOk();
    $seconda = alFinto('GET', '/v1/io/fornitori?limite=1&cursore='.urlencode($prima->json('successivo')), gettone: $cora)->assertOk();
    expect(array_column($seconda->json('data'), 'nome'))->toBe(['Studio Verdi'])->and($seconda->json('successivo'))->toBeNull();

    alFinto('POST', '/v1/io/fornitori/nascosti', ['workspace_id' => $rossi['id']], $cora)->assertCreated();
    expect(array_column(alFinto('GET', '/v1/io/fornitori', gettone: $cora)->json('data'), 'nome'))->toBe(['Studio Verdi']);
    alFinto('DELETE', '/v1/io/fornitori/nascosti/'.$rossi['id'], gettone: $cora)->assertNoContent();
    expect(array_column(alFinto('GET', '/v1/io/fornitori', gettone: $cora)->json('data'), 'nome'))->toBe(['Studio Rossi', 'Studio Verdi'])
        ->and(esito(alFinto('GET', '/v1/io/fornitori?limite=0', gettone: $cora)))->toBe([422, 'dati_non_validi'])
        ->and(esito(alFinto('GET', '/v1/io/fornitori')))->toBe([401, 'gettone_assente']);
});
