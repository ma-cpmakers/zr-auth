<?php

use Zeiras\Auth\Testing\BackofficeFinto;

// T6 (#2460, PM-11, backoffice): la lista `progetto` di una scheda nel finto, `assegnato_a` e `scheda_id` sui to-do, le viste e il riepilogo che
// contano per ciascuno i soli to-do assegnati a lui, il filtro `scheda_id`, i 409 di una scheda archiviata e il vincolo dello stesso tipo. Che
// le risposte e gli errori siano quelli del backoffice lo prova il FintoTest del backoffice.

/**
 * Un workspace con `pm`, Anna e Bruno e una scheda: [finto, gettoni per nome, scheda, id di Anna, id di Bruno].
 *
 * @return array{BackofficeFinto, array<string, string>, array<string, mixed>, string, string, array<string, mixed>}
 */
function studioConLaScheda(): array
{
    $finto = BackofficeFinto::attiva();
    $anna = $finto->persona('anna@example.com', PASSWORD, 'Anna');
    $studio = $finto->workspace('Studio', $anna);
    $bruno = $finto->persona('bruno@example.com', PASSWORD, 'Bruno');
    $finto->membro($studio, $bruno, 'membro');
    $finto->attivaApp($studio, 'pm');
    $gettoni = [];

    foreach (['anna', 'bruno'] as $nome) {
        $gettoni[$nome] = alFinto('POST', '/v1/gettoni', ['workspace_id' => $studio['id']], entraNelFinto("{$nome}@example.com")['gettone']['gettone'])->json('data.gettone');
    }

    return [$finto, $gettoni, $finto->scheda($studio, 'Scrivere il piano'), $anna['id'], $bruno['id'], $studio];
}

/** La lista `progetto` di una scheda: l'`id`. */
function listaDelProgetto(string $gettone, string $scheda, string $nome = 'Piano'): string
{
    $risposta = alFinto('POST', '/v1/board/todo/liste', ['nome' => $nome, 'tipo' => 'progetto', 'scheda_id' => $scheda], $gettone);
    expect($risposta->status())->toBe(201, $risposta->body());

    return $risposta->json('data.id');
}

test('la lista progetto nasce con tipo, scheda_id e board_id; la seconda sulla stessa scheda è 409 lista_gia_esistente con lista_id (TP.1)', function () {
    [, $gettoni, $scheda] = studioConLaScheda();

    $uno = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Piano', 'tipo' => 'progetto', 'scheda_id' => $scheda['id']], $gettoni['anna']);
    $due = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'Altra', 'tipo' => 'progetto', 'scheda_id' => $scheda['id']], $gettoni['bruno']);

    expect($uno->status())->toBe(201)
        ->and($uno->json('data.tipo'))->toBe('progetto')
        ->and($uno->json('data.scheda_id'))->toBe($scheda['id'])
        ->and($uno->json('data.board_id'))->toBe($scheda['board_id'])
        ->and($due->status())->toBe(409)
        ->and($due->json('codice'))->toBe('lista_gia_esistente')
        ->and($due->json('lista_id'))->toBe($uno->json('data.id'));
});

test('progetto vuole la scheda, una personale non la accetta, una scheda sconosciuta è 422 sul campo (TP.1)', function () {
    [, $gettoni, $scheda] = studioConLaScheda();

    $senza = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'A', 'tipo' => 'progetto'], $gettoni['anna']);
    $personale = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'B', 'scheda_id' => $scheda['id']], $gettoni['anna']);
    $sconosciuta = alFinto('POST', '/v1/board/todo/liste', ['nome' => 'C', 'tipo' => 'progetto', 'scheda_id' => '01kzzzzzzzzzzzzzzzzzzzzzzz'], $gettoni['anna']);

    expect([$senza->status(), $senza->json('errors.0.pointer'), $senza->json('errors.0.regola')])->toBe([422, '#/scheda_id', 'obbligatorio'])
        ->and([$personale->status(), $personale->json('errors.0.pointer'), $personale->json('errors.0.regola')])->toBe([422, '#/scheda_id', 'campo_vietato'])
        ->and([$sconosciuta->status(), $sconosciuta->json('errors.0.pointer'), $sconosciuta->json('errors.0.regola')])->toBe([422, '#/scheda_id', 'non_trovato']);
});

test('l\'elenco delle liste: le personali e le progetto in cui sei coinvolto; con scheda_id la sola lista di quella scheda (TP.4)', function () {
    [$finto, $gettoni, $scheda, , $bruno] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);

    $fuori = alFinto('GET', '/v1/board/todo/liste', null, $gettoni['bruno'])->json('data');
    $perScheda = alFinto('GET', "/v1/board/todo/liste?scheda_id={$scheda['id']}", null, $gettoni['bruno']);
    alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Per Bruno', 'lista_id' => $lista, 'assegnato_a' => $bruno], $gettoni['anna']);
    $dentro = alFinto('GET', '/v1/board/todo/liste', null, $gettoni['bruno'])->json('data');

    expect(array_column($fuori, 'tipo'))->toBe(['personale'])
        ->and(array_column($perScheda->json('data'), 'id'))->toBe([$lista])
        ->and(array_column($dentro, 'tipo'))->toBe(['personale', 'progetto'])
        ->and(alFinto('GET', '/v1/board/todo/liste?scheda_id=01kzzzzzzzzzzzzzzzzzzzzzzz', null, $gettoni['anna'])->json('errors.0.parameter'))->toBe('scheda_id')
        ->and(alFinto('GET', '/v1/board/todo/liste?altro=1', null, $gettoni['anna'])->json('errors.0.parameter'))->toBe('altro');
});

test('un membro scrive nella lista progetto di un altro; la personale di un altro resta fuori (TP.3)', function () {
    [$finto, $gettoni, $scheda] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $personaleDiAnna = alFinto('GET', '/v1/board/todo/liste', null, $gettoni['anna'])->json('data.0.id');

    $suo = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Di Bruno', 'lista_id' => $lista], $gettoni['bruno']);
    $modifica = alFinto('PATCH', "/v1/board/todo/liste/{$lista}", ['nome' => 'Piano condiviso'], $gettoni['bruno']);

    expect($suo->status())->toBe(201)
        ->and($suo->json('data.scheda_id'))->toBe($scheda['id'])
        ->and($suo->json('data.assegnato_a'))->toBeNull()
        ->and($modifica->status())->toBe(200)
        ->and(alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Intruso', 'lista_id' => $personaleDiAnna], $gettoni['bruno'])->json('errors.0.pointer'))->toBe('#/lista_id')
        ->and(alFinto('PATCH', "/v1/board/todo/liste/{$personaleDiAnna}", ['nome' => 'Mia'], $gettoni['bruno'])->status())->toBe(404);
});

test('un to-do nasce nella lista progetto della scheda_id, con l\'assegnato; assegnato in una personale e scheda diversa sono 422 campo_vietato (TP.5)', function () {
    [, $gettoni, $scheda, , $bruno] = studioConLaScheda();
    $senzaLista = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Dove?', 'scheda_id' => $scheda['id']], $gettoni['anna']);
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);

    $nato = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Scrivere', 'scheda_id' => $scheda['id'], 'assegnato_a' => $bruno], $gettoni['anna']);
    $inPersonale = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Mio', 'assegnato_a' => $bruno], $gettoni['anna']);
    $diversa = alFinto('POST', '/v1/board/todo/elementi', ['titolo' => 'Storto', 'lista_id' => $lista, 'scheda_id' => '01kzzzzzzzzzzzzzzzzzzzzzzz'], $gettoni['anna']);

    expect([$senzaLista->status(), $senzaLista->json('errors.0.pointer')])->toBe([422, '#/scheda_id'])
        ->and($nato->status())->toBe(201)
        ->and($nato->json('data.lista_id'))->toBe($lista)
        ->and($nato->json('data.scheda_id'))->toBe($scheda['id'])
        ->and($nato->json('data.assegnato_a'))->toBe($bruno)
        ->and([$inPersonale->status(), $inPersonale->json('errors.0.pointer'), $inPersonale->json('errors.0.regola')])->toBe([422, '#/assegnato_a', 'campo_vietato'])
        ->and([$diversa->status(), $diversa->json('errors.0.pointer'), $diversa->json('errors.0.regola')])->toBe([422, '#/scheda_id', 'campo_vietato']);
});

test('le viste e il riepilogo contano in una lista progetto i soli to-do assegnati a chi chiama; scheda_id li legge tutti, aperti e fatti (TP.7, TP.8)', function () {
    [, $gettoni, $scheda, $anna, $bruno] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $oggi = now('Europe/Rome')->format('Y-m-d');
    $perAnna = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Per Anna', 'lista_id' => $lista, 'assegnato_a' => $anna, 'giorno' => $oggi]);
    $perBruno = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Per Bruno', 'lista_id' => $lista, 'assegnato_a' => $bruno, 'giorno' => $oggi]);
    nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Di nessuno', 'lista_id' => $lista, 'giorno' => $oggi]);
    alFinto('POST', "/v1/board/todo/elementi/{$perBruno}/completamento", null, $gettoni['bruno']);

    $oggiAnna = collect(alFinto('GET', '/v1/board/todo/elementi?vista=oggi', null, $gettoni['anna'])->json('data'))->pluck('id')->all();
    $oggiBruno = collect(alFinto('GET', '/v1/board/todo/elementi?vista=oggi', null, $gettoni['bruno'])->json('data'))->pluck('id')->all();
    $scheda_ = collect(alFinto('GET', "/v1/board/todo/elementi?scheda_id={$scheda['id']}", null, $gettoni['bruno'])->json('data'))->pluck('titolo')->all();
    $riepilogo = alFinto('GET', '/v1/board/todo/riepilogo', null, $gettoni['anna'])->json('data');
    $dellaLista = collect($riepilogo['liste'])->firstWhere('lista_id', $lista);

    expect($oggiAnna)->toBe([$perAnna])
        ->and($oggiBruno)->toBe([])
        ->and($scheda_)->toEqualCanonicalizing(['Per Anna', 'Per Bruno', 'Di nessuno'])
        ->and($riepilogo['di_oggi'])->toBe(1)
        ->and($dellaLista)->toBe(['lista_id' => $lista, 'aperti' => 2, 'fatti' => 1])
        ->and(alFinto('GET', "/v1/board/todo/elementi?scheda_id={$scheda['id']}&vista=oggi", null, $gettoni['anna'])->status())->toBe(422);
});

test('assegnato_a=me legge i to-do di progetto assegnati a chi chiama, aperti o fatti; totale li conta; assegnati è nel riepilogo (F7, #2465 #2467)', function () {
    [, $gettoni, $scheda, $anna, $bruno] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $oggi = now('Europe/Rome')->format('Y-m-d');
    $tardi = now('Europe/Rome')->addDays(2)->format('Y-m-d');
    $tardiAnna = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Tardi', 'lista_id' => $lista, 'assegnato_a' => $anna, 'giorno' => $tardi]);
    $oggiAnna = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Oggi', 'lista_id' => $lista, 'assegnato_a' => $anna, 'giorno' => $oggi]);
    $fattoAnna = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Fatto', 'lista_id' => $lista, 'assegnato_a' => $anna]);
    $perBruno = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Per Bruno', 'lista_id' => $lista, 'assegnato_a' => $bruno]);
    nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Di nessuno', 'lista_id' => $lista]);
    alFinto('POST', "/v1/board/todo/elementi/{$fattoAnna}/completamento", null, $gettoni['anna']);
    $ids = fn (string $query, string $chi) => collect(alFinto('GET', "/v1/board/todo/elementi?{$query}", null, $gettoni[$chi])->json('data'))->pluck('id')->all();

    $paginato = alFinto('GET', '/v1/board/todo/elementi?assegnato_a=me&limite=1&totale=true', null, $gettoni['anna']);
    $riepilogoAnna = alFinto('GET', '/v1/board/todo/riepilogo', null, $gettoni['anna'])->json('data');

    expect($ids('assegnato_a=me', 'anna'))->toBe([$oggiAnna, $tardiAnna])
        ->and($ids("assegnato_a={$anna}", 'anna'))->toBe([$oggiAnna, $tardiAnna])
        ->and($ids('assegnato_a=me&fatti=true', 'anna'))->toBe([$fattoAnna])
        ->and($ids('assegnato_a=me', 'bruno'))->toBe([$perBruno])
        ->and($paginato->json('data'))->toHaveCount(1)
        ->and($paginato->json('totale'))->toBe(2)
        ->and($paginato->json('successivo'))->not->toBeNull()
        ->and($paginato->json())->not->toHaveKey('totale_almeno')
        ->and(alFinto('GET', '/v1/board/todo/elementi?assegnato_a=me', null, $gettoni['anna'])->json())->not->toHaveKey('totale')
        ->and(alFinto('GET', '/v1/board/todo/elementi?vista=tutti&totale=true', null, $gettoni['anna'])->json('totale'))->toBe(2)
        ->and(array_keys($riepilogoAnna))->toBe(['oggi', 'in_ritardo', 'di_oggi', 'settimana', 'aperti', 'fatti', 'assegnati', 'liste'])
        ->and($riepilogoAnna['assegnati'])->toBe(2)
        ->and(alFinto('GET', '/v1/board/todo/riepilogo', null, $gettoni['bruno'])->json('data.assegnati'))->toBe(1);
});

test('assegnato_a vuole me o il proprio id, sta da solo fra i filtri e totale è true o false (F7)', function () {
    [, $gettoni, $scheda, , $bruno] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $errore = fn (string $query) => alFinto('GET', "/v1/board/todo/elementi?{$query}", null, $gettoni['anna']);

    $altro = $errore("assegnato_a={$bruno}");

    expect([$altro->status(), $altro->json('errors.0.parameter'), $altro->json('errors.0.regola')])->toBe([422, 'assegnato_a', 'valore_non_ammesso'])
        ->and($errore('assegnato_a=me&vista=oggi')->status())->toBe(422)
        ->and($errore("assegnato_a=me&lista_id={$lista}")->status())->toBe(422)
        ->and($errore("assegnato_a=me&scheda_id={$scheda['id']}")->status())->toBe(422)
        ->and($errore('assegnato_a=me&totale=1')->json('errors.0.parameter'))->toBe('totale')
        ->and($errore('totale=true')->status())->toBe(422);
});

test('un to-do cambia lista solo fra liste dello stesso tipo; fra due progetto prende la scheda di arrivo (TP.10)', function () {
    [$finto, $gettoni, $scheda] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $personale = alFinto('GET', '/v1/board/todo/liste', null, $gettoni['anna'])->json('data.0.id');
    $id = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Mobile', 'lista_id' => $lista]);

    $errore = alFinto('PATCH', "/v1/board/todo/elementi/{$id}", ['lista_id' => $personale], $gettoni['anna']);

    expect([$errore->status(), $errore->json('errors.0.pointer'), $errore->json('errors.0.regola')])->toBe([422, '#/lista_id', 'valore_non_ammesso']);
});

test('una scheda archiviata si legge ma ogni scrittura nella sua lista è 409 scheda_archiviata (TP.9)', function () {
    [$finto, $gettoni, $scheda] = studioConLaScheda();
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $id = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Uno', 'lista_id' => $lista]);

    $finto->segnaScheda($scheda, 'archiviata');

    foreach ([
        ['POST', '/v1/board/todo/elementi', ['titolo' => 'Due', 'lista_id' => $lista]],
        ['PATCH', "/v1/board/todo/elementi/{$id}", ['titolo' => 'Cambiato']],
        ['POST', "/v1/board/todo/elementi/{$id}/completamento", null],
        ['DELETE', "/v1/board/todo/elementi/{$id}", null],
        ['PATCH', "/v1/board/todo/liste/{$lista}", ['nome' => 'Altro']],
    ] as [$metodo, $percorso, $corpo]) {
        expect(alFinto($metodo, $percorso, $corpo, $gettoni['anna'])->json('codice'))->toBe('scheda_archiviata');
    }

    expect(alFinto('GET', "/v1/board/todo/elementi?lista_id={$lista}", null, $gettoni['anna'])->json('data'))->toHaveCount(1);
});

/** Un to-do di una lista progetto: l'`id`. */
function nuovoTodoDelProgetto(string $gettone, array $corpo): string
{
    $risposta = alFinto('POST', '/v1/board/todo/elementi', $corpo, $gettone);
    expect($risposta->status())->toBe(201, $risposta->body());

    return $risposta->json('data.id');
}

test('spostato in una lista progetto di un\'altra board, il to-do perde l\'assegnatario che ha lasciato il workspace e la risposta dice assegnatario_tolto (#2812)', function () {
    [$finto, $gettoni, $scheda, , $bruno, $studio] = studioConLaScheda();
    $altra = $finto->scheda($studio, 'Fuori', 'altra-board');
    $stessa = $finto->scheda($studio, 'Seconda');
    $lista = listaDelProgetto($gettoni['anna'], $scheda['id']);
    $listaAltra = listaDelProgetto($gettoni['anna'], $altra['id'], 'Fuori');
    $listaStessa = listaDelProgetto($gettoni['anna'], $stessa['id'], 'Seconda');
    $resta = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Resta', 'lista_id' => $lista, 'assegnato_a' => $bruno]);
    $parte = nuovoTodoDelProgetto($gettoni['anna'], ['titolo' => 'Parte', 'lista_id' => $lista, 'assegnato_a' => $bruno]);

    $ancora = alFinto('PATCH', "/v1/board/todo/elementi/{$resta}", ['lista_id' => $listaAltra], $gettoni['anna']);
    expect($ancora->status())->toBe(200, $ancora->body())
        ->and($ancora->json('data.assegnato_a'))->toBe($bruno)
        ->and($ancora->json('assegnatario_tolto'))->toBeFalse();

    expect(alFinto('DELETE', "/v1/workspace/membri/{$bruno}", null, $gettoni['anna'])->status())->toBe(204);

    $stessaBoard = alFinto('PATCH', "/v1/board/todo/elementi/{$parte}", ['lista_id' => $listaStessa], $gettoni['anna']);
    expect($stessaBoard->json('assegnatario_tolto'))->toBeFalse()->and($stessaBoard->json('data.assegnato_a'))->toBe($bruno);

    $tolto = alFinto('PATCH', "/v1/board/todo/elementi/{$parte}", ['lista_id' => $listaAltra], $gettoni['anna']);
    expect($tolto->status())->toBe(200, $tolto->body())
        ->and($tolto->json('data.lista_id'))->toBe($listaAltra)
        ->and($tolto->json('data.assegnato_a'))->toBeNull()
        ->and($tolto->json('assegnatario_tolto'))->toBeTrue();
});
